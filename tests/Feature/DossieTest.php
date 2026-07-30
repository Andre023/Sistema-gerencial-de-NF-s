<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dossiê do fornecedor: histórico de UM fornecedor comparado com a média da
 * rede. Também aqui canceladas/excluídas ficam fora dos números de trabalho.
 */
class DossieTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $preLote;
    private Fornecedor $chua;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin   = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->chua    = Fornecedor::create(['nome' => 'CHUA ALIMENTOS']);
    }

    private function nota(Fornecedor $f, array $extra = []): Nota
    {
        return Nota::create(array_merge([
            'numero_nota'   => (string) random_int(1000, 99999),
            'fornecedor_id' => $f->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    private function props(array $params)
    {
        $r = $this->actingAs($this->admin)->get(route('dossie.index', $params));
        $r->assertOk();
        return $r->viewData('page')['props'];
    }

    // ── Acesso ───────────────────────────────────────────────────────────────

    public function test_pagina_abre_para_admin(): void
    {
        $this->actingAs($this->admin)->get(route('dossie.index'))->assertOk();
    }

    public function test_nao_admin_nao_acessa(): void
    {
        $this->actingAs($this->preLote)->get(route('dossie.index'))->assertForbidden();
    }

    // ── Busca ────────────────────────────────────────────────────────────────

    public function test_busca_encontra_fornecedor(): void
    {
        Fornecedor::create(['nome' => 'SPAL BEBIDAS']);

        $props = $this->props(['busca' => 'CHUA']);
        $this->assertCount(1, $props['resultados']);
        $this->assertSame('CHUA ALIMENTOS', $props['resultados'][0]['nome']);
    }

    public function test_resultado_unico_abre_o_dossie_direto(): void
    {
        $this->nota($this->chua);

        $props = $this->props(['busca' => 'CHUA']);
        $this->assertNotNull($props['fornecedor']);
        $this->assertNotNull($props['dossie']);
    }

    public function test_sem_fornecedor_nao_monta_dossie(): void
    {
        $this->assertNull($this->props([])['dossie']);
    }

    // ── Números ──────────────────────────────────────────────────────────────

    public function test_kpis_do_fornecedor(): void
    {
        $limpa = $this->nota($this->chua);
        $this->actingAs($this->preLote)->post(route('notas.liberar', $limpa));

        $comErro = $this->nota($this->chua);
        $comErro->cards()->create(['tipo' => 'custo', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id]);

        $k = $this->props(['fornecedor_id' => $this->chua->id])['dossie']['kpis'];

        $this->assertSame(2, $k['total']);
        $this->assertSame(1, $k['liberadas']);
        $this->assertSame(50.0, $k['qualidade']); // 1 de 2 notas passou limpa
    }

    public function test_divergencias_comparam_com_a_rede(): void
    {
        // CHUA: 1 nota, 1 card de custo -> 100 por 100 notas
        $n = $this->nota($this->chua);
        $n->cards()->create(['tipo' => 'custo', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id]);

        // Outro fornecedor: 3 notas limpas (puxa a média da rede para baixo)
        $outro = Fornecedor::create(['nome' => 'BOM FORNECEDOR']);
        for ($i = 0; $i < 3; $i++) $this->nota($outro);

        $d = collect($this->props(['fornecedor_id' => $this->chua->id])['dossie']['divergencias'])
            ->firstWhere('motivo', 'Custo');

        $this->assertSame(1, $d['total']);
        $this->assertSame(100.0, $d['taxa']);      // 1 card / 1 nota
        $this->assertSame(25.0, $d['taxaRede']);   // 1 card / 4 notas da rede
        $this->assertSame(4.0, $d['vezes']);       // 4x acima da média
    }

    public function test_cancelada_fica_fora_do_total_mas_aparece_no_kpi(): void
    {
        $viva = $this->nota($this->chua);
        $cancelada = $this->nota($this->chua);
        $this->actingAs($this->preLote)->post(route('notas.cancelar', $cancelada));

        $k = $this->props(['fornecedor_id' => $this->chua->id])['dossie']['kpis'];
        $this->assertSame(1, $k['total'], 'cancelada não entra no total de trabalho');
        $this->assertSame(1, $k['canceladas']);
        $this->assertNotNull($viva);
    }

    public function test_excluida_nao_entra(): void
    {
        $this->nota($this->chua);
        $apagada = $this->nota($this->chua);
        $apagada->delete();

        $this->assertSame(1, $this->props(['fornecedor_id' => $this->chua->id])['dossie']['kpis']['total']);
    }

    public function test_conta_reaberturas_do_fornecedor(): void
    {
        $n = $this->nota($this->chua);
        $n->cards()->create([
            'tipo' => 'cadastro', 'status' => Card::STATUS_ABERTO,
            'aberto_por' => $this->preLote->id, 'reaberturas' => 3,
        ]);

        $this->assertSame(3, $this->props(['fornecedor_id' => $this->chua->id])['dossie']['kpis']['reaberturas']);
    }

    public function test_lista_ultimas_notas_e_lojas(): void
    {
        $this->nota($this->chua, ['loja' => 2]);
        $this->nota($this->chua, ['loja' => 3]);

        $d = $this->props(['fornecedor_id' => $this->chua->id])['dossie'];
        $this->assertCount(2, $d['ultimas']);
        $this->assertCount(2, $d['porLoja']);
    }
}
