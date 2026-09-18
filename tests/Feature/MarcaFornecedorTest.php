<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configurações › Consignados / Feira / Uso e consumo — as marcas de
 * fornecedor. A marca é do FORNECEDOR, e toda nota dele chega à tela já com
 * `fornecedor.<coluna>` — sem ninguém marcar nada ao lançar, ao contrário do
 * CEASA.
 *
 * Quem vê: qualquer conta (o visitante inclusive). Quem marca: todos menos o
 * visitante, a mesma régua de Matriz/Filial.
 *
 * Os testes rodam para as TRÊS marcas: são a mesma página e o mesmo
 * controller, e o que se confere é que nenhuma delas ficou de fora.
 */
class MarcaFornecedorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $preLote;
    private User $recebimento;
    private User $compras;
    private User $visitante;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin       = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->visitante   = User::factory()->create(['role' => User::ROLE_VISITANTE]);
    }

    /** @return array<string, array{string, string}> slug → coluna */
    public static function marcas(): array
    {
        return collect(Fornecedor::MARCAS)
            ->mapWithKeys(fn($m, $slug) => [$slug => [$slug, $m['coluna']]])
            ->all();
    }

    // ── Acesso ────────────────────────────────────────────────────────────────

    /** @dataProvider marcas */
    public function test_a_lista_abre_para_qualquer_conta(string $slug): void
    {
        foreach ([$this->admin, $this->preLote, $this->recebimento, $this->compras, $this->visitante] as $quem) {
            $this->actingAs($quem)
                ->get(route('configuracoes.marca', $slug))
                ->assertOk()
                ->assertInertia(fn($page) => $page
                    ->component('Configuracoes/Marca')
                    ->where('marca', $slug));
        }
    }

    public function test_slug_desconhecido_e_404(): void
    {
        $this->actingAs($this->admin)->get('/configuracoes/qualquer-coisa')->assertNotFound();
    }

    /** @dataProvider marcas */
    public function test_quem_opera_marca_e_desmarca(string $slug, string $coluna): void
    {
        $forn = Fornecedor::create(['nome' => 'HORTIFRUTI DO VALE']);

        foreach ([$this->preLote, $this->recebimento, $this->compras, $this->admin] as $quem) {
            $this->actingAs($quem)
                ->patch(route('configuracoes.marca.alternar', [$slug, $forn]), ['marcado' => true])
                ->assertRedirect();
            $this->assertTrue($forn->fresh()->{$coluna});

            $this->actingAs($quem)
                ->patch(route('configuracoes.marca.alternar', [$slug, $forn]), ['marcado' => false])
                ->assertRedirect();
            $this->assertFalse($forn->fresh()->{$coluna});
        }
    }

    /** @dataProvider marcas */
    public function test_visitante_ve_mas_nao_marca(string $slug, string $coluna): void
    {
        $forn = Fornecedor::create(['nome' => 'HORTIFRUTI DO VALE']);

        $this->actingAs($this->visitante)
            ->patch(route('configuracoes.marca.alternar', [$slug, $forn]), ['marcado' => true])
            ->assertForbidden();

        $this->assertFalse($forn->fresh()->{$coluna});
    }

    /** @dataProvider marcas */
    public function test_a_lista_traz_so_os_marcados_e_a_busca_acha_os_demais(string $slug, string $coluna): void
    {
        $sim = Fornecedor::create(['nome' => 'MARCADO LTDA', $coluna => true]);
        Fornecedor::create(['nome' => 'COMUM LTDA']);

        $this->actingAs($this->preLote)
            ->get(route('configuracoes.marca', [$slug, 'busca' => 'LTDA']))
            ->assertInertia(fn($page) => $page
                ->has('marcados', 1)
                ->where('marcados.0.id', $sim->id)
                ->has('resultados', 2)
                ->where('busca', 'LTDA')
            );
    }

    public function test_as_marcas_sao_independentes(): void
    {
        // Marcar como feira não faz o fornecedor virar consignado, e vice-versa.
        $forn = Fornecedor::create(['nome' => 'HORTIFRUTI DO VALE']);

        $this->actingAs($this->preLote)
            ->patch(route('configuracoes.marca.alternar', ['feira', $forn]), ['marcado' => true]);

        $forn->refresh();
        $this->assertTrue($forn->feira);
        $this->assertFalse($forn->consignado);
        $this->assertFalse($forn->uso_consumo);

        $this->actingAs($this->preLote)
            ->get(route('configuracoes.marca', 'consignados'))
            ->assertInertia(fn($page) => $page->has('marcados', 0));
    }

    // ── Efeito na fila ──────────────────────────────────────────────────────────

    public function test_a_nota_chega_a_tela_com_todas_as_marcas_do_fornecedor(): void
    {
        $tresMarcas = Fornecedor::create([
            'nome' => 'TUDO LTDA', 'consignado' => true, 'feira' => true, 'uso_consumo' => true,
        ]);
        $comum = Fornecedor::create(['nome' => 'COMUM LTDA']);

        $daMarca = $this->notaDe($tresMarcas);
        $daComum = $this->notaDe($comum);

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('preLote.0.id', $daMarca->id)
                ->where('preLote.0.fornecedor.consignado', true)
                ->where('preLote.0.fornecedor.feira', true)
                ->where('preLote.0.fornecedor.uso_consumo', true)
                ->where('preLote.1.id', $daComum->id)
                ->where('preLote.1.fornecedor.consignado', false)
                ->where('preLote.1.fornecedor.feira', false)
                ->where('preLote.1.fornecedor.uso_consumo', false)
            );
    }

    /** @dataProvider marcas */
    public function test_marcar_o_fornecedor_alcanca_a_nota_ja_lancada(string $slug, string $coluna): void
    {
        $forn = Fornecedor::create(['nome' => 'HORTIFRUTI DO VALE']);
        $nota = $this->notaDe($forn);

        $this->actingAs($this->preLote)
            ->patch(route('configuracoes.marca.alternar', [$slug, $forn]), ['marcado' => true]);

        // Nada foi gravado na nota: a marca vem do fornecedor na hora de ler.
        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('preLote.0.id', $nota->id)
                ->where("preLote.0.fornecedor.{$coluna}", true)
            );
    }

    // ── A porta de Configurações ───────────────────────────────────────────────

    public function test_configuracoes_manda_cada_papel_para_a_primeira_secao_dele(): void
    {
        $this->actingAs($this->admin)->get(route('configuracoes.index'))
            ->assertRedirect(route('usuarios.index'));

        foreach ([$this->preLote, $this->recebimento, $this->compras] as $quem) {
            $this->actingAs($quem)->get(route('configuracoes.index'))
                ->assertRedirect(route('fornecedores.index'));
        }

        $this->actingAs($this->visitante)->get(route('configuracoes.index'))
            ->assertRedirect(route('configuracoes.marca', 'consignados'));
    }

    public function test_as_secoes_do_admin_continuam_fechadas_para_os_outros(): void
    {
        foreach ([$this->preLote, $this->recebimento, $this->compras, $this->visitante] as $quem) {
            $this->actingAs($quem)->get(route('usuarios.index'))->assertForbidden();
            $this->actingAs($quem)->get(route('configuracoes.campanha'))->assertForbidden();
            $this->actingAs($quem)->get(route('configuracoes.fornecedores'))->assertForbidden();
        }
    }

    private function notaDe(Fornecedor $forn): Nota
    {
        return Nota::create([
            'numero_nota'   => (string) random_int(1000, 9999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'pre_lote',
        ]);
    }
}
