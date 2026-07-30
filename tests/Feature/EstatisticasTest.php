<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estatísticas. O foco principal é a REGRA DE OURO: nota cancelada ou excluída
 * não é trabalho pendente e não pode entrar nos números — sem isso a cancelada
 * vira "pendente eterna" e derruba a taxa de resolução.
 */
class EstatisticasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $preLote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin   = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
    }

    private function nota(array $extra = [], string $fornecedor = 'FORN'): Nota
    {
        $f = Fornecedor::firstOrCreate(['nome' => $fornecedor]);
        return Nota::create(array_merge([
            'numero_nota'   => (string) random_int(1000, 99999),
            'fornecedor_id' => $f->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    private function stats(array $params = [])
    {
        $r = $this->actingAs($this->admin)->get(route('estatisticas.index', $params));
        $r->assertOk();
        return $r->viewData('page')['props'];
    }

    // ── O BUG: canceladas e excluídas ────────────────────────────────────────

    public function test_cancelada_nao_conta_como_pendente(): void
    {
        $this->nota(['numero_nota' => 'VIVA']);
        $cancelada = $this->nota(['numero_nota' => 'CANC']);

        $antes = $this->stats();
        $this->assertSame(2, $antes['kpis']['total']);
        $this->assertSame(2, $antes['kpis']['pendentes']);

        $this->actingAs($this->preLote)->post(route('notas.cancelar', $cancelada));

        $depois = $this->stats();
        $this->assertSame(1, $depois['kpis']['total'], 'cancelada não pode entrar no total');
        $this->assertSame(1, $depois['kpis']['pendentes'], 'cancelada não pode virar pendente eterna');
    }

    public function test_cancelada_nao_aparece_nas_pendentes_mais_antigas(): void
    {
        $velha = $this->nota(['numero_nota' => 'VELHA']);
        $velha->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();

        $this->actingAs($this->preLote)->post(route('notas.cancelar', $velha));

        $props = $this->stats();
        $numeros = collect($props['pendentesMaisAntigas'])->pluck('numero_nota');
        $this->assertNotContains('VELHA', $numeros);
    }

    public function test_cancelada_nao_derruba_a_taxa_de_resolucao(): void
    {
        $liberada = $this->nota(['numero_nota' => 'OK']);
        $this->actingAs($this->preLote)->post(route('notas.liberar', $liberada));

        $cancelada = $this->nota(['numero_nota' => 'X']);
        $this->actingAs($this->preLote)->post(route('notas.cancelar', $cancelada));

        // 1 liberada de 1 nota ativa = 100% (a cancelada saiu do denominador)
        $this->assertSame(100.0, $this->stats()['kpis']['taxaResolucao']);
    }

    public function test_excluida_nao_entra_nos_numeros(): void
    {
        $this->nota(['numero_nota' => 'FICA']);
        $apagada = $this->nota(['numero_nota' => 'SOME']);
        $apagada->delete(); // soft delete

        $this->assertSame(1, $this->stats()['kpis']['total']);
    }

    public function test_card_de_nota_cancelada_sai_do_por_motivo(): void
    {
        $nota = $this->nota();
        $nota->cards()->create(['tipo' => 'custo', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id]);

        $this->assertSame(1, collect($this->stats()['porMotivo'])->firstWhere('motivo', 'Custo')['total']);

        $this->actingAs($this->preLote)->post(route('notas.cancelar', $nota));

        $this->assertNull(collect($this->stats()['porMotivo'])->firstWhere('motivo', 'Custo'));
    }

    // ── Blocos novos ─────────────────────────────────────────────────────────

    public function test_cancelamento_tem_bloco_proprio(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->preLote)->post(route('notas.cancelar', $nota), ['motivo' => 'fornecedor cancelou']);

        $c = $this->stats()['cancelamento'];
        $this->assertSame(1, $c['total']);
        $this->assertSame($this->preLote->name, $c['porQuem'][0]['usuario']);
    }

    public function test_retrabalho_conta_reaberturas(): void
    {
        $nota = $this->nota();
        $nota->cards()->create([
            'tipo' => 'custo', 'status' => Card::STATUS_ABERTO,
            'aberto_por' => $this->preLote->id, 'reaberturas' => 2,
        ]);

        $r = $this->stats()['retrabalho'];
        $this->assertSame(1, $r['reabertos']);
        $this->assertSame(2, $r['porTipo'][0]['reaberturas']);
    }

    public function test_separa_por_origem_e_por_ceasa(): void
    {
        $this->nota(['origem' => 'recebimento']);
        $this->nota(['origem' => 'pre_lote']);
        $this->nota(['ceasa' => 1]);

        $props = $this->stats();
        $this->assertCount(2, $props['porOrigem']);
        $this->assertCount(2, $props['porCeasa']);

        $ceasa = collect($props['porCeasa'])->firstWhere('grupo', 'CEASA');
        $this->assertSame(1, $ceasa['total']);
    }

    public function test_filtra_por_loja(): void
    {
        $this->nota(['loja' => 1]);
        $this->nota(['loja' => 2]);
        $this->nota(['loja' => 2]);

        $this->assertSame(2, $this->stats(['loja' => [2]])['kpis']['total']);
    }

    public function test_kpis_trazem_variacao(): void
    {
        $this->nota();
        $props = $this->stats();
        $this->assertArrayHasKey('variacao', $props['kpis']);
    }

    public function test_nao_admin_nao_acessa(): void
    {
        $this->actingAs($this->preLote)->get(route('estatisticas.index'))->assertForbidden();
    }
}
