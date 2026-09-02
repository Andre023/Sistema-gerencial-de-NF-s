<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O filtro "Contar": pela entrada ou pela saída.
 *
 * Existe porque uma nota antecipada no pré-lote entra num dia e sai noutro, e as
 * duas perguntas dão números diferentes sem nada estar errado. A tela contava só
 * pela entrada e por isso discordava da planilha de "Liberadas neste dia" — num
 * dia real, 186 contra 256.
 */
class EstatisticaContarPorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->fornecedor = Fornecedor::create(['nome' => 'TESTE'])->id;
    }

    private function nota(string $criada, ?string $liberada): Nota
    {
        $n = Nota::create([
            'numero_nota' => (string) random_int(1000, 999999),
            'fornecedor_id' => $this->fornecedor, 'user_id' => $this->admin->id,
            'loja' => 1, 'origem' => 'recebimento',
        ]);

        $n->forceFill([
            'created_at'   => $criada,
            'liberada_em'  => $liberada,
            'liberada_por' => $liberada ? $this->admin->id : null,
        ])->saveQuietly();

        return $n->fresh();
    }

    /** O cenário que gerou a dúvida, em miniatura. */
    private function cenario(): void
    {
        // Entrou ontem, saiu hoje — o pré-lote esperando o caminhão
        $this->nota('2026-09-01 08:00:00', '2026-09-02 10:00:00');
        $this->nota('2026-09-01 09:00:00', '2026-09-02 11:00:00');
        // Entrou e saiu hoje
        $this->nota('2026-09-02 08:00:00', '2026-09-02 09:00:00');
        // Entrou hoje e ainda está na fila
        $this->nota('2026-09-02 08:30:00', null);
    }

    private function kpis(string $contarPor): array
    {
        return $this->actingAs($this->admin)
            ->get(route('estatisticas.index', [
                'de' => '2026-09-02', 'ate' => '2026-09-02', 'contarPor' => $contarPor,
            ]))
            ->assertOk()
            ->viewData('page')['props']['kpis'];
    }

    /** Pela ENTRADA: as que chegaram hoje. */
    public function test_contando_por_lancadas(): void
    {
        $this->cenario();

        $kpis = $this->kpis('lancadas');

        $this->assertSame(2, $kpis['total'], 'duas entraram hoje');
        $this->assertSame(1, $kpis['atendidas'], 'e uma delas ja saiu');
        $this->assertSame(1, $kpis['pendentes']);
    }

    /**
     * Pela SAÍDA: tudo o que saiu hoje, tenha entrado quando tiver.
     *
     * É o número que a planilha de "Liberadas neste dia" mostra.
     */
    public function test_contando_por_liberadas(): void
    {
        $this->cenario();

        $kpis = $this->kpis('liberadas');

        $this->assertSame(3, $kpis['total'], 'tres sairam hoje — duas vieram de ontem');
        $this->assertSame(1, $kpis['resolvidasNoDia'], 'so uma entrou e saiu no mesmo dia');
    }

    /**
     * "Na fila" e "taxa" vêm SEMPRE da entrada, nos dois recortes.
     *
     * No recorte por saída toda nota já saiu: a fila daria zero e a taxa daria
     * 100% — não por estarem certas, mas por a pergunta não existir ali.
     */
    public function test_fila_e_taxa_nao_mentem_no_recorte_por_saida(): void
    {
        $this->cenario();

        $porSaida = $this->kpis('liberadas');

        $this->assertSame(1, $porSaida['pendentes'], 'a fila continua sendo das que entraram');
        $this->assertSame(2, $porSaida['lancadas']);
        $this->assertSame(50.0, $porSaida['taxaResolucao'], 'uma das duas que entraram ja saiu');
    }

    /** Sem o filtro, nada muda para quem já usava a tela. */
    public function test_o_padrao_continua_sendo_a_entrada(): void
    {
        $this->cenario();

        $semFiltro = $this->actingAs($this->admin)
            ->get(route('estatisticas.index', ['de' => '2026-09-02', 'ate' => '2026-09-02']))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('lancadas', $semFiltro['contarPor']);
        $this->assertSame(2, $semFiltro['kpis']['total']);
    }
}
