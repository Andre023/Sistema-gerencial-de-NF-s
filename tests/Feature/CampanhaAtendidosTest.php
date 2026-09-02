<?php

namespace Tests\Feature;

use App\Models\CampanhaAtendimento;
use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lista de fornecedores atendidos na campanha — de cada comprador.
 */
class CampanhaAtendidosTest extends TestCase
{
    use RefreshDatabase;

    private User $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracao::definirCampanhaAtiva(true);
        $this->comprador = User::factory()->create(['role' => User::ROLE_COMPRAS]);
    }

    /** O 2% sai sozinho quando a tela não manda a meta. */
    public function test_incluir_calcula_os_2_por_cento_do_faturamento(): void
    {
        $this->actingAs($this->comprador)
            ->postJson(route('campanha.atendidos.incluir'), [
                'fornecedor'  => 'Vilma Alimentos',
                'faturamento' => 1250000,
            ])
            ->assertCreated();

        $a = CampanhaAtendimento::firstOrFail();

        // assertEquals e nao assertSame: o JSON devolve 25000 e nao 25000.0
        // quando o valor e redondo, e o que importa aqui e o numero.
        $this->assertEquals(25000, $a->investimento);
        $this->assertEquals(0, $a->pago);
        $this->assertEquals(25000, $a->falta());
    }

    /**
     * O exemplo do André: meta de 25.000, pagou 10.000.
     *
     * 40% pago, faltam 15.000.
     */
    public function test_o_pago_vira_percentual_e_quanto_falta(): void
    {
        $a = CampanhaAtendimento::create([
            'user_id' => $this->comprador->id, 'fornecedor' => 'Vilma', 'chave' => 'VILMA',
            'faturamento' => 1250000, 'investimento' => 25000, 'pago' => 0,
        ]);

        $this->actingAs($this->comprador)
            ->patchJson(route('campanha.atendidos.atualizar', $a), ['pago' => 10000])
            ->assertOk();

        $this->assertEquals(40, $a->fresh()->percentualPago());
        $this->assertEquals(15000, $a->fresh()->falta());
    }

    /**
     * Quem pagou a mais não "deve menos que nada".
     *
     * Falta zero, e o excedente aparece pelo percentual passando de 100%.
     */
    public function test_pagou_a_mais_nao_gera_falta_negativa(): void
    {
        $a = CampanhaAtendimento::create([
            'user_id' => $this->comprador->id, 'fornecedor' => 'X', 'chave' => 'X',
            'faturamento' => null, 'investimento' => 1000, 'pago' => 0,
        ]);

        $this->actingAs($this->comprador)
            ->patchJson(route('campanha.atendidos.atualizar', $a), ['pago' => 1500])
            ->assertOk();

        $this->assertEquals(0, $a->fresh()->falta());
        $this->assertEquals(150, $a->fresh()->percentualPago());
    }

    /** Meta zero não tem percentual: mostrar 0% afirmaria que falta tudo. */
    public function test_meta_zero_nao_inventa_percentual(): void
    {
        $a = CampanhaAtendimento::create([
            'user_id' => $this->comprador->id, 'fornecedor' => 'X', 'chave' => 'X',
            'faturamento' => null, 'investimento' => 0, 'pago' => 0,
        ]);

        $this->assertNull($a->percentualPago());
    }

    /**
     * Os valores ficam CONGELADOS na linha.
     *
     * A planilha de compras troca a campanha_fornecedores inteira a cada envio.
     * Se a meta fosse recalculada dali, o acordo fechado hoje seria cobrado
     * amanhã por outro faturamento — a meta se mexeria sozinha depois de
     * combinada.
     */
    public function test_a_meta_nao_muda_quando_a_planilha_muda(): void
    {
        $this->actingAs($this->comprador)
            ->postJson(route('campanha.atendidos.incluir'), [
                'fornecedor' => 'Vilma', 'faturamento' => 1000000,
            ])->assertCreated();

        // A planilha nova chega e o faturamento do fornecedor dobra
        \App\Models\CampanhaFornecedor::create([
            'nome' => 'Vilma', 'chave' => 'VILMA', 'faturamento' => 2000000,
        ]);

        $this->actingAs($this->comprador)
            ->getJson(route('campanha.atendidos'))
            ->assertOk();

        $a = CampanhaAtendimento::firstOrFail();

        $this->assertEquals(20000, $a->investimento, 'a meta foi congelada no acordo');
        $this->assertEquals(1000000, $a->faturamento, 'o faturamento tambem');
    }

    /** Incluir duas vezes leria o "quanto falta" em dobro. */
    public function test_nao_inclui_o_mesmo_fornecedor_duas_vezes(): void
    {
        $this->actingAs($this->comprador)
            ->postJson(route('campanha.atendidos.incluir'), ['fornecedor' => 'Vilma Alimentos'])
            ->assertCreated();

        // Mesmo parceiro escrito de outro jeito: a chave reconhece
        $this->actingAs($this->comprador)
            ->postJson(route('campanha.atendidos.incluir'), ['fornecedor' => '  vilma   alimentos '])
            ->assertStatus(422)
            ->assertJsonPath('erro', 'Este fornecedor já está na sua lista.');

        $this->assertSame(1, CampanhaAtendimento::count());
    }

    /**
     * TODOS veem a lista inteira — e e isso que evita dois compradores baterem
     * no mesmo fornecedor. O filtro por nome mora na tela.
     */
    public function test_a_lista_e_de_todos_e_diz_de_quem_e_cada_linha(): void
    {
        $outro = User::factory()->create(['role' => User::ROLE_COMPRAS, 'name' => 'Clayton']);

        CampanhaAtendimento::create([
            'user_id' => $outro->id, 'fornecedor' => 'Dele', 'chave' => 'DELE',
            'faturamento' => null, 'investimento' => 100, 'pago' => 0,
        ]);

        $this->actingAs($this->comprador)
            ->getJson(route('campanha.atendidos'))
            ->assertOk()
            ->assertJsonCount(1, 'atendidos')
            ->assertJsonPath('atendidos.0.comprador', 'Clayton')
            ->assertJsonPath('atendidos.0.user_id', $outro->id);
    }

    /** Ver e de todos; MEXER continua sendo do dono. */
    public function test_nao_mexo_na_linha_de_outro_comprador(): void
    {
        $outro = User::factory()->create(['role' => User::ROLE_COMPRAS]);

        $dele = CampanhaAtendimento::create([
            'user_id' => $outro->id, 'fornecedor' => 'Dele', 'chave' => 'DELE',
            'faturamento' => null, 'investimento' => 100, 'pago' => 0,
        ]);

        $this->actingAs($this->comprador)
            ->patchJson(route('campanha.atendidos.atualizar', $dele), ['pago' => 999])
            ->assertNotFound();

        $this->assertSame('0.00', $dele->fresh()->pago);
    }

    /**
     * O admin mexe na linha de qualquer um.
     *
     * A lista sobrevive a ferias e a desligamento: sem ele, um acordo ficaria
     * congelado esperando alguem que nao volta.
     */
    public function test_admin_mexe_na_linha_de_qualquer_um(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $dele = CampanhaAtendimento::create([
            'user_id' => $this->comprador->id, 'fornecedor' => 'X', 'chave' => 'X',
            'faturamento' => null, 'investimento' => 100, 'pago' => 0,
        ]);

        $this->actingAs($admin)
            ->patchJson(route('campanha.atendidos.atualizar', $dele), ['pago' => 50])
            ->assertOk();

        $this->assertEquals(50, $dele->fresh()->pago);
    }

    /**
     * Fornecedor ja incluido por OUTRO comprador: a recusa diz de quem e.
     *
     * "Ja esta na lista" mandaria a pessoa procurar numa lista de dezenas para
     * descobrir com quem falar.
     */
    public function test_duplicado_de_outro_comprador_diz_o_nome_dele(): void
    {
        $clayton = User::factory()->create(['role' => User::ROLE_COMPRAS, 'name' => 'Clayton']);

        CampanhaAtendimento::create([
            'user_id' => $clayton->id, 'fornecedor' => 'Vilma Alimentos', 'chave' => 'VILMA ALIMENTOS',
            'faturamento' => null, 'investimento' => 100, 'pago' => 0,
        ]);

        $this->actingAs($this->comprador)
            ->postJson(route('campanha.atendidos.incluir'), ['fornecedor' => 'vilma  alimentos'])
            ->assertStatus(422)
            ->assertJsonPath('erro', 'Clayton já incluiu este fornecedor na lista dele.');

        $this->assertSame(1, CampanhaAtendimento::count());
    }

    /** A exportacao sai como .xlsx que o Excel abre de verdade. */
    public function test_exporta_planilha_do_excel(): void
    {
        CampanhaAtendimento::create([
            'user_id' => $this->comprador->id, 'fornecedor' => 'Vilma Alimentos', 'chave' => 'VILMA ALIMENTOS',
            'faturamento' => 1250000, 'investimento' => 25000, 'pago' => 10000,
        ]);

        $r = $this->actingAs($this->comprador)
            ->get(route('campanha.atendidos.exportar'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Grava e abre como ZIP: se o arquivo estiver malformado, o Excel
        // recusaria abrir e este teste e o unico lugar onde isso aparece antes.
        $caminho = tempnam(sys_get_temp_dir(), 'xlsxteste');
        file_put_contents($caminho, $r->getContent());

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($caminho) === true, 'o .xlsx tem de ser um ZIP valido');

        $aba = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($caminho);

        $this->assertIsString($aba);
        $this->assertStringContainsString('Vilma Alimentos', $aba);
        $this->assertStringContainsString('Comprador', $aba, 'o cabecalho vai junto');
        // O numero vai como NUMERO, e nao texto: e o que permite somar a coluna
        $this->assertStringContainsString('<v>25000</v>', $aba);
        $this->assertStringContainsString('<v>10000</v>', $aba);
        $this->assertStringContainsString('<v>40</v>', $aba, 'o % pago calculado');
    }

    public function test_remover_tira_da_lista(): void
    {
        $a = CampanhaAtendimento::create([
            'user_id' => $this->comprador->id, 'fornecedor' => 'X', 'chave' => 'X',
            'faturamento' => null, 'investimento' => 100, 'pago' => 0,
        ]);

        $this->actingAs($this->comprador)
            ->deleteJson(route('campanha.atendidos.remover', $a))
            ->assertOk();

        $this->assertSame(0, CampanhaAtendimento::count());
    }
}
