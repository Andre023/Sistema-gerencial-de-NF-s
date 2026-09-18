<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configurações › Consignados: a marca é do FORNECEDOR, e toda nota dele
 * chega à tela já com `fornecedor.consignado` — sem ninguém marcar nada ao
 * lançar, ao contrário do CEASA.
 *
 * Quem vê: qualquer conta (o visitante inclusive). Quem marca: todos menos o
 * visitante, a mesma régua de Matriz/Filial.
 */
class ConsignadoTest extends TestCase
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

    // ── Acesso ────────────────────────────────────────────────────────────────

    public function test_a_lista_abre_para_qualquer_conta(): void
    {
        foreach ([$this->admin, $this->preLote, $this->recebimento, $this->compras, $this->visitante] as $quem) {
            $this->actingAs($quem)
                ->get(route('configuracoes.consignados'))
                ->assertOk()
                ->assertInertia(fn($page) => $page->component('Configuracoes/Consignados'));
        }
    }

    public function test_quem_opera_marca_e_desmarca(): void
    {
        $forn = Fornecedor::create(['nome' => 'HORTIFRUTI DO VALE']);

        foreach ([$this->preLote, $this->recebimento, $this->compras, $this->admin] as $quem) {
            $this->actingAs($quem)
                ->patch(route('configuracoes.consignados.alternar', $forn), ['consignado' => true])
                ->assertRedirect();
            $this->assertTrue($forn->fresh()->consignado);

            $this->actingAs($quem)
                ->patch(route('configuracoes.consignados.alternar', $forn), ['consignado' => false])
                ->assertRedirect();
            $this->assertFalse($forn->fresh()->consignado);
        }
    }

    public function test_visitante_ve_mas_nao_marca(): void
    {
        $forn = Fornecedor::create(['nome' => 'HORTIFRUTI DO VALE']);

        $this->actingAs($this->visitante)
            ->patch(route('configuracoes.consignados.alternar', $forn), ['consignado' => true])
            ->assertForbidden();

        $this->assertFalse($forn->fresh()->consignado);
    }

    public function test_a_lista_traz_so_os_marcados_e_a_busca_acha_os_demais(): void
    {
        $sim = Fornecedor::create(['nome' => 'CONSIGNADO LTDA', 'consignado' => true]);
        $nao = Fornecedor::create(['nome' => 'COMUM LTDA']);

        $this->actingAs($this->preLote)
            ->get(route('configuracoes.consignados', ['busca' => 'LTDA']))
            ->assertInertia(fn($page) => $page
                ->has('consignados', 1)
                ->where('consignados.0.id', $sim->id)
                ->has('resultados', 2)
                ->where('busca', 'LTDA')
            );

        $this->assertFalse($nao->fresh()->consignado);
    }

    // ── Efeito na fila ──────────────────────────────────────────────────────────

    public function test_a_nota_do_consignado_chega_a_tela_com_a_marca(): void
    {
        $consignado = Fornecedor::create(['nome' => 'CONSIGNADO LTDA', 'consignado' => true]);
        $comum      = Fornecedor::create(['nome' => 'COMUM LTDA']);

        $daMarca = $this->notaDe($consignado);
        $daComum = $this->notaDe($comum);

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('preLote.0.id', $daMarca->id)
                ->where('preLote.0.fornecedor.consignado', true)
                ->where('preLote.1.id', $daComum->id)
                ->where('preLote.1.fornecedor.consignado', false)
            );
    }

    public function test_marcar_o_fornecedor_alcanca_a_nota_ja_lancada(): void
    {
        $forn = Fornecedor::create(['nome' => 'HORTIFRUTI DO VALE']);
        $nota = $this->notaDe($forn);

        $this->actingAs($this->preLote)
            ->patch(route('configuracoes.consignados.alternar', $forn), ['consignado' => true]);

        // Nada foi gravado na nota: a marca vem do fornecedor na hora de ler.
        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('preLote.0.id', $nota->id)
                ->where('preLote.0.fornecedor.consignado', true)
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
            ->assertRedirect(route('configuracoes.consignados'));
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
