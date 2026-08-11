<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Não pode haver notas duplicadas. Uma NF é única por número + fornecedor;
 * ao tentar lançar uma que já existe, o sistema move de fila (com confirmação),
 * bloqueia a fila repetida ou registra a chegada de uma já liberada.
 */
class DuplicataNotaTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $preLote;
    private Fornecedor $forn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->forn        = Fornecedor::create(['nome' => 'CHUA']);
    }

    private function nota(array $extra = []): Nota
    {
        return Nota::create(array_merge([
            'numero_nota'   => '5001',
            'fornecedor_id' => $this->forn->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'pre_lote',
        ], $extra));
    }

    private function lancar(User $quem, array $dados)
    {
        return $this->actingAs($quem)->post(route('notas.store'), array_merge([
            'numero_nota'   => '5001',
            'fornecedor_id' => $this->forn->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $dados));
    }

    // ── Sem duplicata ─────────────────────────────────────────────────────────

    public function test_lanca_nota_normal_quando_nao_existe(): void
    {
        $this->lancar($this->recebimento, [])->assertRedirect();

        $this->assertDatabaseCount('notas', 1);
    }

    public function test_mesmo_numero_com_fornecedor_diferente_nao_e_duplicata(): void
    {
        $outro = Fornecedor::create(['nome' => 'SPAL']);
        $this->nota(); // 5001 / CHUA

        $this->lancar($this->recebimento, ['fornecedor_id' => $outro->id])->assertRedirect();

        $this->assertDatabaseCount('notas', 2);
    }

    // ── Nota cancelada em dia anterior ────────────────────────────────────────

    /**
     * O caso que o recebimento reportou: a nota foi cancelada ontem e hoje
     * tentam lançá-la de novo. A cancelada não era testada em lugar nenhum —
     * caía no teste de "mesma fila" e recebia "já está lançada em ...", que
     * manda procurar na fila uma nota que não está lá.
     */
    public function test_cancelada_avisa_o_cancelamento_e_nao_a_fila(): void
    {
        $this->nota([
            'origem'       => 'recebimento',
            'cancelada_em' => now()->subDays(3),
        ]);

        $resposta = $this->lancar($this->recebimento, ['origem' => 'recebimento']);

        $erro = session('errors')->first('numero_nota');

        $this->assertStringContainsString('cancelada', mb_strtolower($erro));
        $this->assertStringContainsString(now()->subDays(3)->format('d/m'), $erro);
        $this->assertStringNotContainsString('já está lançada', $erro);

        $this->assertDatabaseCount('notas', 1); // não duplicou
    }

    /** Vale para a outra fila também — cancelamento manda antes de mover. */
    public function test_cancelada_avisa_mesmo_vindo_de_outra_fila(): void
    {
        $this->nota([
            'origem'       => 'pre_lote',
            'cancelada_em' => now()->subDay(),
        ]);

        $this->lancar($this->recebimento, ['origem' => 'recebimento']);

        $erro = session('errors')->first('numero_nota');

        $this->assertStringContainsString('cancelada', mb_strtolower($erro));
    }

    /** Cancelada e já liberada antes disso: o cancelamento é o que vale. */
    public function test_cancelada_manda_mesmo_tendo_sido_liberada_antes(): void
    {
        $nota = $this->nota([
            'origem'       => 'recebimento',
            'liberada_em'  => now()->subDays(5),
            'cancelada_em' => now()->subDays(2),
        ]);

        $this->lancar($this->recebimento, ['origem' => 'recebimento']);

        $this->assertStringContainsString('cancelada', mb_strtolower(session('errors')->first('numero_nota')));
        // não pode marcar "recebida hoje" numa nota cancelada
        $this->assertNull($nota->fresh()->recebida_em);
    }

    public function test_nota_excluida_nao_bloqueia_relancar(): void
    {
        $this->nota()->delete(); // soft delete

        $this->lancar($this->recebimento, [])->assertRedirect();

        $this->assertSame(1, Nota::count());              // uma ativa (a nova)
        $this->assertSame(2, Nota::withTrashed()->count()); // + a antiga, trashed
    }

    // ── Mesma fila: bloqueia ──────────────────────────────────────────────────

    public function test_duplicata_na_mesma_fila_e_bloqueada(): void
    {
        $this->nota(['origem' => 'recebimento']);

        $this->lancar($this->recebimento, ['origem' => 'recebimento'])
            ->assertSessionHasErrors('numero_nota');

        $this->assertDatabaseCount('notas', 1);
    }

    // ── Fila diferente: pergunta e move ───────────────────────────────────────

    public function test_fila_diferente_sem_confirmar_pede_confirmacao_e_nao_move(): void
    {
        $this->nota(['origem' => 'pre_lote']); // já está no pré-lote

        // recebimento tenta lançar → deve pedir confirmação (erro "duplicada")
        $this->lancar($this->recebimento, ['origem' => 'recebimento'])
            ->assertSessionHasErrors(['duplicada' => 'pre_lote']);

        $this->assertDatabaseCount('notas', 1);
        $this->assertSame('pre_lote', Nota::first()->origem); // não moveu
    }

    public function test_fila_diferente_confirmada_move_a_nota(): void
    {
        $original = $this->nota(['origem' => 'pre_lote']);

        $this->lancar($this->recebimento, ['origem' => 'recebimento', 'confirmar_mover' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('notas', 1);           // não duplicou
        $this->assertSame('recebimento', $original->fresh()->origem); // moveu
    }

    public function test_movimento_inverso_recebimento_para_pre_lote(): void
    {
        $original = $this->nota(['origem' => 'recebimento']);

        $this->actingAs($this->preLote)->post(route('notas.store'), [
            'numero_nota' => '5001', 'fornecedor_id' => $this->forn->id,
            'loja' => 1, 'origem' => 'pre_lote', 'confirmar_mover' => true,
        ])->assertRedirect();

        $this->assertDatabaseCount('notas', 1);
        $this->assertSame('pre_lote', $original->fresh()->origem);
    }

    public function test_mover_leva_os_cards_junto(): void
    {
        $original = $this->nota(['origem' => 'pre_lote']);
        $original->cards()->create(['tipo' => 'custo', 'status' => 'aberto', 'aberto_por' => $this->preLote->id]);

        $this->lancar($this->recebimento, ['origem' => 'recebimento', 'confirmar_mover' => true])
            ->assertRedirect();

        $original->refresh();
        $this->assertSame('recebimento', $original->origem);
        $this->assertCount(1, $original->cards); // a divergência veio junto
    }

    // ── Já liberada: registra a chegada de hoje ───────────────────────────────

    public function test_ja_liberada_registra_recebida_hoje_sem_reabrir(): void
    {
        $liberada = $this->nota([
            'origem'       => 'pre_lote',
            'liberada_por' => $this->preLote->id,
            'liberada_em'  => now()->subDays(2),
        ]);

        $this->lancar($this->recebimento, ['origem' => 'recebimento'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $liberada->refresh();
        $this->assertDatabaseCount('notas', 1);                       // não duplicou
        $this->assertSame('pre_lote', $liberada->origem);             // não mudou de fila
        $this->assertNotNull($liberada->recebida_em);                 // marcou a chegada
        $this->assertSame($this->preLote->id, $liberada->liberada_por); // liberação preservada
        $this->assertTrue($liberada->liberada_em->isSameDay(now()->subDays(2)));
    }

    public function test_liberada_em_dia_passado_mas_recebida_hoje_aparece_nas_liberadas(): void
    {
        $this->nota([
            'numero_nota'  => 'ONTEM',
            'liberada_por' => $this->preLote->id,
            'liberada_em'  => now()->subDays(3),
            'recebida_em'  => now(),
        ]);

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->has('liberadas', 1)
                ->where('liberadas.0.numero_nota', 'ONTEM')
            );
    }

    // ── Update não pode colidir ───────────────────────────────────────────────

    public function test_editar_para_colidir_com_outra_e_bloqueado(): void
    {
        $this->nota(['numero_nota' => '5001']);
        $outra = $this->nota(['numero_nota' => '9999']);

        $this->actingAs($this->preLote)
            ->patch(route('notas.update', $outra), ['numero_nota' => '5001'])
            ->assertSessionHasErrors('numero_nota');

        $this->assertSame('9999', $outra->fresh()->numero_nota); // não alterou
    }
}
