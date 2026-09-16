<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Ocorrencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Matriz e filial: o mesmo fornecedor cadastrado duas vezes vira um só.
 *
 * O que este arquivo protege:
 *   • as notas da filial passam para a matriz, e cada uma ganha ocorrência
 *   • só a matriz aparece nas listas de escolha, mas o nome da filial a encontra
 *   • nota lançada "na filial" cai na matriz
 *   • um nível só, e ninguém é filial de si mesmo
 *   • todo papel operacional vincula; o visitante não
 */
class FornecedorMatrizFilialTest extends TestCase
{
    use RefreshDatabase;

    private User $preLote;
    private Fornecedor $matriz;
    private Fornecedor $filial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->matriz  = Fornecedor::create(['nome' => 'MOINHO GLOBO ALIMENTOS S.A.']);
        $this->filial  = Fornecedor::create(['nome' => 'MOINHO GLOBO ALIMENTOS S/A']);
    }

    private function nota(Fornecedor $f, string $numero = '1'): Nota
    {
        return Nota::create([
            'numero_nota' => $numero, 'fornecedor_id' => $f->id, 'user_id' => $this->preLote->id,
            'loja' => 1, 'origem' => 'recebimento',
        ]);
    }

    private function vincular(User $quem, Fornecedor $filial, Fornecedor $matriz)
    {
        return $this->actingAs($quem)
            ->patchJson(route('fornecedores.vincular', $filial), ['matriz_id' => $matriz->id]);
    }

    // ─── O vínculo ─────────────────────────────────────────────────────────────

    public function test_notas_da_filial_passam_para_a_matriz_com_ocorrencia(): void
    {
        $n1 = $this->nota($this->filial, '10');
        $n2 = $this->nota($this->filial, '11');
        $daMatriz = $this->nota($this->matriz, '12');

        $this->vincular($this->preLote, $this->filial, $this->matriz)
            ->assertOk()
            ->assertJsonPath('movidas', 2)
            ->assertJsonPath('matriz.notas', 3)
            ->assertJsonPath('matriz.filiais.0.nome', 'MOINHO GLOBO ALIMENTOS S/A');

        $this->assertSame($this->matriz->id, $this->filial->fresh()->matriz_id);
        $this->assertSame($this->matriz->id, $n1->fresh()->fornecedor_id);
        $this->assertSame($this->matriz->id, $n2->fresh()->fornecedor_id);
        $this->assertSame($this->matriz->id, $daMatriz->fresh()->fornecedor_id);

        // Cada nota movida diz de onde veio — com nomes, não ids
        $this->assertDatabaseHas('ocorrencias', [
            'nota_id' => $n1->id,
            'acao'    => Ocorrencia::FORNECEDOR_UNIFICADO,
            'user_id' => $this->preLote->id,
        ]);
        $oc = Ocorrencia::where('nota_id', $n1->id)->where('acao', Ocorrencia::FORNECEDOR_UNIFICADO)->first();
        $this->assertSame('MOINHO GLOBO ALIMENTOS S/A', $oc->dados['contexto']['de']);
        $this->assertSame('MOINHO GLOBO ALIMENTOS S.A.', $oc->dados['contexto']['para']);

        // A da matriz não foi tocada
        $this->assertDatabaseMissing('ocorrencias', ['nota_id' => $daMatriz->id, 'acao' => Ocorrencia::FORNECEDOR_UNIFICADO]);
    }

    public function test_nota_excluida_da_filial_tambem_vai_para_a_matriz(): void
    {
        $n = $this->nota($this->filial);
        $n->delete();

        $this->vincular($this->preLote, $this->filial, $this->matriz)->assertOk();

        $this->assertSame($this->matriz->id, Nota::withTrashed()->find($n->id)->fornecedor_id);
    }

    public function test_filial_que_tinha_filiais_leva_todas_para_a_matriz(): void
    {
        $neta = Fornecedor::create(['nome' => 'MOINHO GLOBO LTDA', 'matriz_id' => $this->filial->id]);

        $this->vincular($this->preLote, $this->filial, $this->matriz)->assertOk();

        $this->assertSame($this->matriz->id, $neta->fresh()->matriz_id);
    }

    public function test_prioridade_da_filial_passa_para_a_matriz(): void
    {
        $this->filial->update(['prioridade' => true]);

        $this->vincular($this->preLote, $this->filial, $this->matriz)->assertOk();

        $this->assertTrue($this->matriz->fresh()->prioridade);
        $this->assertFalse($this->filial->fresh()->prioridade);
    }

    // ─── Regras ────────────────────────────────────────────────────────────────

    public function test_nao_vincula_a_si_mesmo(): void
    {
        $this->vincular($this->preLote, $this->filial, $this->filial)
            ->assertStatus(422)
            ->assertJsonPath('erro', 'Um fornecedor não pode ser filial de si mesmo.');
    }

    public function test_um_nivel_so_nao_vincula_a_uma_filial(): void
    {
        $this->filial->update(['matriz_id' => $this->matriz->id]);
        $outra = Fornecedor::create(['nome' => 'OUTRA']);

        $this->vincular($this->preLote, $outra, $this->filial)
            ->assertStatus(422)
            ->assertJsonFragment(['erro' => '"MOINHO GLOBO ALIMENTOS S/A" já é filial de "MOINHO GLOBO ALIMENTOS S.A.". Vincule direto à matriz.']);

        $this->assertNull($outra->fresh()->matriz_id);
    }

    public function test_desvincular_devolve_a_filial_sem_as_notas(): void
    {
        $n = $this->nota($this->filial);
        $this->vincular($this->preLote, $this->filial, $this->matriz)->assertOk();

        $this->actingAs($this->preLote)
            ->deleteJson(route('fornecedores.desvincular', $this->filial))
            ->assertOk();

        $this->assertNull($this->filial->fresh()->matriz_id);
        $this->assertSame($this->matriz->id, $n->fresh()->fornecedor_id);
    }

    // ─── Quem pode ─────────────────────────────────────────────────────────────

    public function test_todo_papel_operacional_vincula(): void
    {
        foreach ([User::ROLE_RECEBIMENTO, User::ROLE_COMPRAS, User::ROLE_PRE_LOTE, User::ROLE_ADMIN] as $papel) {
            $filial = Fornecedor::create(['nome' => "FILIAL {$papel}"]);
            $quem   = User::factory()->create(['role' => $papel]);

            $this->vincular($quem, $filial, $this->matriz)->assertOk();
            $this->assertSame($this->matriz->id, $filial->fresh()->matriz_id);
        }
    }

    public function test_visitante_nao_vincula_nem_ve_a_aba(): void
    {
        $visitante = User::factory()->create(['role' => User::ROLE_VISITANTE]);

        $this->vincular($visitante, $this->filial, $this->matriz)->assertForbidden();
        $this->actingAs($visitante)->get(route('fornecedores.index'))->assertForbidden();
        $this->assertNull($this->filial->fresh()->matriz_id);
    }

    // ─── Só a matriz aparece, e o nome da filial a encontra ────────────────────

    public function test_lista_de_lancamento_so_tem_matrizes_com_os_nomes_das_filiais(): void
    {
        $this->filial->update(['matriz_id' => $this->matriz->id]);

        // Reload parcial como o navegador manda (a lista é Inertia::optional);
        // a versão precisa ser a real, senão o Inertia responde 409.
        $this->actingAs($this->preLote)
            ->get(route('notas.index'), [
                'X-Inertia'                   => 'true',
                'X-Inertia-Partial-Data'      => 'fornecedores',
                'X-Inertia-Partial-Component' => 'Notas/Index',
                'X-Inertia-Version'           => (new \App\Http\Middleware\HandleInertiaRequests())->version(request()),
            ])
            ->assertOk()
            // Reload parcial devolve o objeto de página em JSON, não a casca HTML
            ->assertJsonCount(1, 'props.fornecedores')
            ->assertJsonPath('props.fornecedores.0.id', $this->matriz->id)
            ->assertJsonPath('props.fornecedores.0.filiais.0', 'MOINHO GLOBO ALIMENTOS S/A');
    }

    public function test_nota_lancada_na_filial_cai_na_matriz(): void
    {
        $this->filial->update(['matriz_id' => $this->matriz->id]);

        $this->actingAs($this->preLote)->post(route('notas.store'), [
            'numero_nota' => '500', 'fornecedor_id' => $this->filial->id, 'loja' => 1, 'origem' => 'pre_lote',
        ])->assertRedirect();

        $this->assertDatabaseHas('notas', ['numero_nota' => '500', 'fornecedor_id' => $this->matriz->id]);
    }

    public function test_fornecedor_novo_com_nome_de_filial_cai_na_matriz(): void
    {
        $this->filial->update(['matriz_id' => $this->matriz->id]);

        $this->actingAs($this->preLote)->post(route('notas.store'), [
            'numero_nota' => '501', 'fornecedor_novo' => true, 'fornecedor_nome' => 'moinho globo alimentos s/a',
            'loja' => 1, 'origem' => 'pre_lote',
        ])->assertRedirect();

        $this->assertDatabaseHas('notas', ['numero_nota' => '501', 'fornecedor_id' => $this->matriz->id]);
        $this->assertDatabaseCount('fornecedores', 2);
    }

    public function test_busca_da_fila_pelo_nome_da_filial_acha_as_notas_da_matriz(): void
    {
        $this->filial->update(['matriz_id' => $this->matriz->id]);
        $n = $this->nota($this->matriz, '77');

        $this->actingAs($this->preLote)
            ->get(route('notas.index', ['busca' => 'S/A']))
            ->assertInertia(fn($page) => $page->has('recebimento', 1)->where('recebimento.0.id', $n->id));
    }

    public function test_busca_da_aba_mostra_o_que_cada_um_e(): void
    {
        $this->filial->update(['matriz_id' => $this->matriz->id]);

        $this->actingAs($this->preLote)
            ->getJson(route('fornecedores.buscar', ['q' => 'MOINHO']))
            ->assertOk()
            ->assertJsonCount(2, 'fornecedores')
            ->assertJsonPath('fornecedores.0.filiais.0.id', $this->filial->id)
            ->assertJsonPath('fornecedores.1.matriz.id', $this->matriz->id);
    }

    public function test_pagina_lista_os_vinculos(): void
    {
        $this->filial->update(['matriz_id' => $this->matriz->id]);

        $this->actingAs($this->preLote)
            ->get(route('fornecedores.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Fornecedores/Vinculos')
                ->has('vinculos', 1)
                ->where('vinculos.0.id', $this->matriz->id)
                ->where('vinculos.0.filiais.0.nome', 'MOINHO GLOBO ALIMENTOS S/A'));
    }
}
