<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O fluxo real de ponta a ponta:
 * recebimento lança → pré-lote abre cards → compras corrige → pré-lote reconfere → libera.
 */
class FluxoNotaTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $preLote;
    private User $compras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
    }

    private function nota(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORNECEDOR TESTE']);

        return Nota::create(array_merge([
            'numero_nota'   => (string) rand(1000, 99999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    private function cardAberto(Nota $nota, string $tipo = 'cadastro'): Card
    {
        return $nota->cards()->create([
            'tipo'       => $tipo,
            'status'     => Card::STATUS_ABERTO,
            'aberto_por' => $this->preLote->id,
        ]);
    }

    // ── Lançar ────────────────────────────────────────────────────────────────

    public function test_recebimento_lanca_nota(): void
    {
        $forn = Fornecedor::create(['nome' => 'FORN A']);

        $this->actingAs($this->recebimento)->post(route('notas.store'), [
            'numero_nota'   => '4625',
            'fornecedor_id' => $forn->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ])->assertRedirect();

        $this->assertDatabaseHas('notas', ['numero_nota' => '4625', 'origem' => 'recebimento']);
    }

    public function test_compras_nao_lanca_nota(): void
    {
        $forn = Fornecedor::create(['nome' => 'FORN B']);

        $this->actingAs($this->compras)->post(route('notas.store'), [
            'numero_nota'   => '999',
            'fornecedor_id' => $forn->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ])->assertForbidden();
    }

    // ── Cards: abrir ──────────────────────────────────────────────────────────

    public function test_pre_lote_abre_card(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro', 'detalhe' => 'Item sem cadastro'])
            ->assertRedirect();

        $this->assertDatabaseHas('cards', [
            'nota_id'    => $nota->id,
            'tipo'       => 'cadastro',
            'status'     => 'aberto',
            'aberto_por' => $this->preLote->id,
        ]);
    }

    public function test_recebimento_nao_abre_card(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->recebimento)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro'])
            ->assertForbidden();
    }

    public function test_nao_duplica_card_ativo_do_mesmo_tipo(): void
    {
        $nota = $this->nota();
        $this->cardAberto($nota, 'regra');

        $this->actingAs($this->preLote)
            ->from(route('notas.index'))
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra'])
            ->assertSessionHasErrors('tipo');

        $this->assertSame(1, $nota->cards()->count());
    }

    // ── Cards: corrigir (compras) ─────────────────────────────────────────────

    public function test_compras_marca_card_corrigido(): void
    {
        $nota = $this->nota();
        $card = $this->cardAberto($nota);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect();

        $card->refresh();
        $this->assertSame(Card::STATUS_CORRIGIDO, $card->status);
        $this->assertSame($this->compras->id, $card->corrigido_por);
        $this->assertNotNull($card->corrigido_em);
    }

    public function test_recebimento_nao_marca_corrigido(): void
    {
        $nota = $this->nota();
        $card = $this->cardAberto($nota);

        $this->actingAs($this->recebimento)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertForbidden();
    }

    // ── Cards: reconferir (pré-lote resolve ou reabre) ────────────────────────

    public function test_pre_lote_resolve_card_corrigido(): void
    {
        $nota = $this->nota();
        $card = $this->cardAberto($nota);
        $card->update(['status' => Card::STATUS_CORRIGIDO, 'corrigido_por' => $this->compras->id, 'corrigido_em' => now()]);

        $this->actingAs($this->preLote)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertRedirect();

        $card->refresh();
        $this->assertSame(Card::STATUS_RESOLVIDO, $card->status);
        $this->assertSame($this->preLote->id, $card->resolvido_por);
    }

    public function test_pre_lote_reabre_card_ainda_errado(): void
    {
        $nota = $this->nota();
        $card = $this->cardAberto($nota);
        $card->update(['status' => Card::STATUS_CORRIGIDO, 'corrigido_por' => $this->compras->id, 'corrigido_em' => now()]);

        $this->actingAs($this->preLote)
            ->patch(route('notas.cards.reabrir', [$nota, $card]))
            ->assertRedirect();

        $card->refresh();
        $this->assertSame(Card::STATUS_ABERTO, $card->status);
        $this->assertSame(1, $card->reaberturas);
        $this->assertNull($card->corrigido_por);
    }

    public function test_compras_nao_resolve_card(): void
    {
        $nota = $this->nota();
        $card = $this->cardAberto($nota);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertForbidden();
    }

    // ── Liberação ─────────────────────────────────────────────────────────────

    public function test_nao_libera_com_card_ativo(): void
    {
        $nota = $this->nota();
        $this->cardAberto($nota);

        $this->actingAs($this->preLote)
            ->from(route('notas.index'))
            ->post(route('notas.liberar', $nota))
            ->assertSessionHasErrors('nota');

        $this->assertNull($nota->fresh()->liberada_em);
    }

    public function test_pre_lote_libera_nota_limpa(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.liberar', $nota))
            ->assertRedirect();

        $nota->refresh();
        $this->assertNotNull($nota->liberada_em);
        $this->assertSame($this->preLote->id, $nota->liberada_por);
    }

    public function test_libera_apos_todos_os_cards_resolvidos(): void
    {
        $nota = $this->nota();
        $card = $this->cardAberto($nota);
        $card->update(['status' => Card::STATUS_RESOLVIDO, 'resolvido_por' => $this->preLote->id, 'resolvido_em' => now()]);

        $this->actingAs($this->preLote)
            ->post(route('notas.liberar', $nota))
            ->assertRedirect();

        $this->assertNotNull($nota->fresh()->liberada_em);
    }

    public function test_recebimento_e_compras_nao_liberam(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->recebimento)->post(route('notas.liberar', $nota))->assertForbidden();
        $this->actingAs($this->compras)->post(route('notas.liberar', $nota))->assertForbidden();
    }

    // ── Status derivado ───────────────────────────────────────────────────────

    public function test_status_deriva_do_ciclo_dos_cards(): void
    {
        $nota = $this->nota();
        $nota->load('cards');
        $this->assertSame(Nota::STATUS_PENDENTE, $nota->statusCalculado());

        $card = $this->cardAberto($nota);
        $this->assertSame(Nota::STATUS_DIVERGENCIA, $nota->fresh()->load('cards')->statusCalculado());

        $card->update(['status' => Card::STATUS_CORRIGIDO]);
        $this->assertSame(Nota::STATUS_RECONFERIR, $nota->fresh()->load('cards')->statusCalculado());

        $card->update(['status' => Card::STATUS_RESOLVIDO]);
        $this->assertSame(Nota::STATUS_PENDENTE, $nota->fresh()->load('cards')->statusCalculado());

        $nota->update(['liberada_em' => now(), 'liberada_por' => $this->preLote->id]);
        $this->assertSame(Nota::STATUS_LIBERADA, $nota->fresh()->load('cards')->statusCalculado());
    }

    // ── Fila do dia ───────────────────────────────────────────────────────────

    public function test_fila_separa_recebimento_de_pre_lote_e_liberadas(): void
    {
        $this->nota(['numero_nota' => 'R1', 'origem' => 'recebimento']);
        $this->nota(['numero_nota' => 'P1', 'origem' => 'pre_lote']);
        $liberada = $this->nota(['numero_nota' => 'L1']);
        $liberada->update(['liberada_em' => now(), 'liberada_por' => $this->preLote->id]);

        $this->actingAs($this->recebimento)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->has('recebimento', 1)
                ->where('recebimento.0.numero_nota', 'R1')
                ->has('preLote', 1)
                ->where('preLote.0.numero_nota', 'P1')
                ->has('liberadas', 1)
                ->where('liberadas.0.numero_nota', 'L1'));
    }

    // ── Estatísticas continuam só de admin ────────────────────────────────────

    public function test_estatisticas_somente_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($this->preLote)->get(route('estatisticas.index'))->assertForbidden();
        $this->actingAs($this->recebimento)->get(route('estatisticas.index'))->assertForbidden();
        $this->assertTrue($admin->podeVerEstatisticas());
    }
}
