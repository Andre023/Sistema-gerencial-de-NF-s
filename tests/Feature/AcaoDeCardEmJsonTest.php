<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As ações de card devolvem SÓ a nota alterada quando a tela pede JSON.
 *
 * O caminho antigo (redirect + fila inteira) continua existindo e é o que os
 * outros testes exercitam — este arquivo cobre o novo, e principalmente a
 * fronteira entre os dois.
 */
class AcaoDeCardEmJsonTest extends TestCase
{
    use RefreshDatabase;

    private User $preLote;
    private User $compras;
    private Nota $nota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->compras = User::factory()->create(['role' => User::ROLE_COMPRAS]);

        $this->nota = Nota::create([
            'numero_nota'   => '7',
            'fornecedor_id' => Fornecedor::create(['nome' => 'VERDE CAMPO'])->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    public function test_abrir_card_devolve_a_nota_pronta_para_a_tela(): void
    {
        $r = $this->actingAs($this->preLote)
            ->postJson(route('notas.cards.store', $this->nota), ['tipo' => 'custo'])
            ->assertOk();

        // A nota volta no MESMO formato que a listagem usa — é o que permite a
        // tela aplicar a linha sem saber por onde ela chegou.
        $r->assertJsonPath('nota.id', $this->nota->id)
            ->assertJsonPath('nota.numero_nota', '7')
            ->assertJsonPath('nota.status', 'com_divergencia')
            ->assertJsonPath('nota.cards.0.tipo', 'custo')
            ->assertJsonPath('sucesso', 'Divergência registrada.');

        // E as relações que a tela desenha vêm juntas
        $r->assertJsonStructure(['nota' => ['fornecedor' => ['nome'], 'user', 'cards', 'nivel', 'dias_aberta']]);
    }

    /** A resposta é a nota SOZINHA — nunca a fila inteira. */
    public function test_a_resposta_nao_traz_as_listas(): void
    {
        $json = $this->actingAs($this->preLote)
            ->postJson(route('notas.cards.store', $this->nota), ['tipo' => 'custo'])
            ->assertOk()
            ->json();

        $this->assertSame(['nota', 'sucesso'], array_keys($json));
        foreach (['recebimento', 'preLote', 'liberadas', 'canceladas', 'fornecedores'] as $lista) {
            $this->assertArrayNotHasKey($lista, $json);
        }
    }

    public function test_corrigir_e_resolver_devolvem_o_estado_novo(): void
    {
        $card = $this->nota->cards()->create([
            'tipo' => 'custo', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);

        $this->actingAs($this->compras)
            ->patchJson(route('notas.cards.corrigir', [$this->nota, $card]))
            ->assertOk()
            ->assertJsonPath('nota.cards.0.status', Card::STATUS_RESOLVIDO)
            // Sem card em aberto, a nota já volta pronta para liberar
            ->assertJsonPath('nota.status', 'reconferir');
    }

    public function test_excluir_card_devolve_a_nota_sem_ele(): void
    {
        $card = $this->nota->cards()->create([
            'tipo' => 'custo', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);

        $this->actingAs($this->preLote)
            ->deleteJson(route('notas.cards.destroy', [$this->nota, $card]))
            ->assertOk()
            ->assertJsonCount(0, 'nota.cards')
            ->assertJsonPath('nota.status', 'pendente');
    }

    /**
     * Regra do negócio recusada vira 422 com a mensagem pronta.
     *
     * 422 e não 400 porque é o mesmo código da validação do Laravel: o
     * tratamento de erro que a tela já tem para os outros pedidos em axios
     * serve sem caso especial.
     */
    public function test_regra_recusada_volta_422_com_a_mensagem(): void
    {
        $this->nota->cards()->create([
            'tipo' => 'custo', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);

        $this->actingAs($this->preLote)
            ->postJson(route('notas.cards.store', $this->nota), ['tipo' => 'custo'])
            ->assertStatus(422)
            ->assertJsonPath('erro', 'Já existe um card de custo em aberto nesta nota.');
    }

    /**
     * O caminho antigo continua intacto.
     *
     * É o que a tela usa quando há filtro ativo — ali ela não pode aplicar a
     * linha sozinha, porque não sabe se a nota alterada ainda pertence à lista
     * filtrada. Se este teste quebrar, o filtro parou de funcionar.
     */
    public function test_sem_pedir_json_continua_redirecionando(): void
    {
        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $this->nota), ['tipo' => 'custo'])
            ->assertRedirect()
            ->assertSessionHas('sucesso', 'Divergência registrada.');
    }

    /** Permissão vem antes do formato: quem não pode, não pode em JSON também. */
    public function test_visitante_nao_abre_card_nem_por_json(): void
    {
        $visitante = User::factory()->create(['role' => User::ROLE_VISITANTE]);

        $this->actingAs($visitante)
            ->postJson(route('notas.cards.store', $this->nota), ['tipo' => 'custo'])
            ->assertForbidden();

        $this->assertDatabaseCount('cards', 0);
    }
}
