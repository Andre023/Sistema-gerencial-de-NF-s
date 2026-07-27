<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os filtros da tela de notas: busca, loja, envelhecimento (nível),
 * fase (reconferir) e tipo de divergência (card).
 */
class FiltroNotasTest extends TestCase
{
    use RefreshDatabase;

    private User $preLote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
    }

    private function nota(array $extra = [], ?string $fornecedor = null): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => $fornecedor ?? 'FORNECEDOR TESTE']);

        $nota = Nota::create(array_merge([
            'numero_nota'   => (string) rand(1000, 99999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));

        // created_at é gerenciado pelo Eloquent — para envelhecer a nota
        // precisamos escrever direto e recarregar.
        if (isset($extra['created_at'])) {
            $nota->forceFill(['created_at' => $extra['created_at']])->saveQuietly();
            $nota->refresh();
        }

        return $nota;
    }

    private function card(Nota $nota, string $tipo, string $status = Card::STATUS_ABERTO): Card
    {
        return $nota->cards()->create([
            'tipo'       => $tipo,
            'status'     => $status,
            'aberto_por' => $this->preLote->id,
        ]);
    }

    /** Números das notas devolvidas nas duas filas */
    private function numerosNaFila(array $params): array
    {
        $resposta = $this->actingAs($this->preLote)->get(route('notas.index', $params));
        $resposta->assertOk();

        $page = $resposta->viewData('page')['props'];

        return collect([...$page['recebimento'], ...$page['preLote']])
            ->pluck('numero_nota')
            ->sort()
            ->values()
            ->all();
    }

    // ── Busca e loja ──────────────────────────────────────────────────────────

    public function test_filtra_por_loja(): void
    {
        $this->nota(['numero_nota' => 'L1', 'loja' => 1]);
        $this->nota(['numero_nota' => 'L2', 'loja' => 2]);

        $this->assertSame(['L2'], $this->numerosNaFila(['loja' => 2]));
    }

    public function test_filtra_por_varias_lojas(): void
    {
        $this->nota(['numero_nota' => 'L1', 'loja' => 1]);
        $this->nota(['numero_nota' => 'L2', 'loja' => 2]);
        $this->nota(['numero_nota' => 'L3', 'loja' => 3]);
        $this->nota(['numero_nota' => 'L9', 'loja' => 9]);

        // Marcando 2, 3 e 9 → só essas aparecem (a 1 fica de fora)
        $this->assertSame(['L2', 'L3', 'L9'], $this->numerosNaFila(['loja' => [2, 3, 9]]));
    }

    public function test_busca_por_numero_e_por_fornecedor(): void
    {
        $this->nota(['numero_nota' => '5001'], 'CHUA ALIMENTOS');
        $this->nota(['numero_nota' => '7002'], 'SPAL BEBIDAS');

        $this->assertSame(['5001'], $this->numerosNaFila(['busca' => '5001']));
        $this->assertSame(['7002'], $this->numerosNaFila(['busca' => 'SPAL']));
    }

    // ── Chips de envelhecimento e fase ────────────────────────────────────────

    public function test_filtra_por_nivel_critico(): void
    {
        $this->nota(['numero_nota' => 'NOVA']);
        $this->nota(['numero_nota' => 'VELHA', 'created_at' => now()->subDays(10)]);

        $this->assertSame(['VELHA'], $this->numerosNaFila(['nivel' => 'critico']));
    }

    public function test_filtra_por_status_reconferir(): void
    {
        $pendente = $this->nota(['numero_nota' => 'PEND']);
        $aberta   = $this->nota(['numero_nota' => 'ABERTA']);
        $this->card($aberta, 'cadastro');
        $pronta = $this->nota(['numero_nota' => 'PRONTA']);
        $this->card($pronta, 'custo', Card::STATUS_RESOLVIDO);

        $this->assertSame(['PRONTA'], $this->numerosNaFila(['status' => 'reconferir']));
        $this->assertNotNull($pendente);
    }

    public function test_contadores_mantem_o_panorama_do_dia_mesmo_com_nivel_filtrado(): void
    {
        $this->nota(['numero_nota' => 'C1', 'created_at' => now()->subDays(10)]);
        $this->nota(['numero_nota' => 'A1', 'created_at' => now()->subDays(4)]);

        $this->actingAs($this->preLote)
            ->get(route('notas.index', ['nivel' => 'critico']))
            ->assertInertia(fn($page) => $page
                ->where('resumoAlertas.critico', 1)
                ->where('resumoAlertas.alerta', 1)   // continua contando, mesmo filtrado
                ->has('recebimento', 1)
            );
    }

    // ── Tipo de divergência (card) ────────────────────────────────────────────

    public function test_filtra_por_tipo_de_card(): void
    {
        $comCadastro = $this->nota(['numero_nota' => 'CAD']);
        $this->card($comCadastro, 'cadastro');

        $comCusto = $this->nota(['numero_nota' => 'CUS']);
        $this->card($comCusto, 'custo');

        $this->nota(['numero_nota' => 'SEMCARD']);

        $this->assertSame(['CAD'], $this->numerosNaFila(['tipo' => 'cadastro']));
        $this->assertSame(['CUS'], $this->numerosNaFila(['tipo' => 'custo']));
    }

    public function test_filtro_de_tipo_considera_so_o_card_ativo(): void
    {
        // Divergência de cadastro que JÁ foi resolvida: a nota não deve mais
        // aparecer como "tem cadastro pendente".
        $resolvida = $this->nota(['numero_nota' => 'RESOLV']);
        $this->card($resolvida, 'cadastro', Card::STATUS_RESOLVIDO);

        $aberta = $this->nota(['numero_nota' => 'ABERTA']);
        $this->card($aberta, 'cadastro');

        $this->assertSame(['ABERTA'], $this->numerosNaFila(['tipo' => 'cadastro']));
    }

    public function test_tipo_invalido_e_ignorado(): void
    {
        $this->nota(['numero_nota' => 'N1']);

        $this->assertSame(['N1'], $this->numerosNaFila(['tipo' => 'inventado']));
    }

    public function test_contador_por_tipo_ignora_card_resolvido_e_sobrevive_ao_filtro(): void
    {
        $a = $this->nota(['numero_nota' => 'A']);
        $this->card($a, 'custo');

        $b = $this->nota(['numero_nota' => 'B']);
        $this->card($b, 'custo');
        $this->card($b, 'cadastro');

        $c = $this->nota(['numero_nota' => 'C']);
        $this->card($c, 'cadastro', Card::STATUS_RESOLVIDO); // histórico, não conta

        $this->actingAs($this->preLote)
            ->get(route('notas.index', ['tipo' => 'custo']))
            ->assertInertia(fn($page) => $page
                ->where('resumoTipos.custo', 2)
                ->where('resumoTipos.cadastro', 1)     // só a de B, a de C está resolvida
                ->where('resumoTipos.regra', 0)
                ->where('resumoTipos.quantidade', 0)
                ->has('recebimento', 2)                 // o filtro trouxe A e B
                ->where('filtros.tipo', 'custo')
            );
    }
}
