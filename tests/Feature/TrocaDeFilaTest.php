<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ao trocar de fila (pré-lote → caminhão na porta) o relógio do envelhecimento
 * REINICIA: a nota esperou na fila anterior, não na atual. Guardamos de onde
 * veio para a tela mostrar "Pré-lote desde 19/06".
 *
 * Cobre também as lojas novas (7, 13, 18) e o card "Reconferir" (só CEASA).
 */
class TrocaDeFilaTest extends TestCase
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

    // ── Troca de fila reinicia a contagem ─────────────────────────────────────

    public function test_mover_de_fila_reinicia_a_contagem_e_guarda_a_origem(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);

        // Nota antiga no pré-lote (5 dias) → seria "alerta"
        $antiga = Nota::create([
            'numero_nota' => '777', 'fornecedor_id' => $forn->id,
            'user_id' => $this->preLote->id, 'loja' => 1, 'origem' => 'pre_lote',
        ]);
        $antiga->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();
        $antiga->refresh();
        $this->assertSame(Nota::NIVEL_ALERTA, $antiga->nivelAlerta(now()->toDateString()));

        // Relançada como caminhão na porta (confirmando a mudança de fila)
        $this->actingAs($this->recebimento)->post(route('notas.store'), [
            'numero_nota' => '777', 'fornecedor_id' => $forn->id,
            'loja' => 1, 'origem' => 'recebimento', 'confirmar_mover' => true,
        ])->assertRedirect();

        $antiga->refresh();
        $this->assertSame('recebimento', $antiga->origem);
        $this->assertSame('pre_lote', $antiga->origem_anterior);
        $this->assertNotNull($antiga->origem_alterada_em);

        // O relógio recomeçou: hoje ela é "normal" na fila nova...
        $this->assertSame(Nota::NIVEL_NORMAL, $antiga->nivelAlerta(now()->toDateString()));
        $this->assertSame(0, $antiga->diasEmAberto(now()->toDateString()));
        // ...mas o created_at (tempo total) continua intacto para o histórico
        $this->assertSame(now()->subDays(5)->toDateString(), $antiga->created_at->toDateString());
    }

    public function test_tela_expoe_a_fila_anterior(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);
        $nota = Nota::create([
            'numero_nota' => '888', 'fornecedor_id' => $forn->id,
            'user_id' => $this->preLote->id, 'loja' => 1, 'origem' => 'pre_lote',
        ]);
        $nota->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $this->actingAs($this->recebimento)->post(route('notas.store'), [
            'numero_nota' => '888', 'fornecedor_id' => $forn->id,
            'loja' => 1, 'origem' => 'recebimento', 'confirmar_mover' => true,
        ]);

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('recebimento.0.origem_anterior', 'pre_lote')
                ->where('recebimento.0.origem_anterior_data', now()->subDays(3)->format('d/m'))
            );
    }

    public function test_nota_que_nunca_mudou_de_fila_conta_da_criacao(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);
        $nota = Nota::create([
            'numero_nota' => '999', 'fornecedor_id' => $forn->id,
            'user_id' => $this->preLote->id, 'loja' => 1, 'origem' => 'recebimento',
        ]);
        $nota->forceFill(['created_at' => now()->subDays(4)])->saveQuietly();
        $nota->refresh();

        $this->assertNull($nota->origem_anterior);
        $this->assertSame(4, $nota->diasEmAberto(now()->toDateString()));
    }

    // ── Lojas novas ───────────────────────────────────────────────────────────

    public function test_lojas_novas_aceitas(): void
    {
        foreach ([7, 13, 18] as $i => $loja) {
            $forn = Fornecedor::firstOrCreate(['nome' => "F{$loja}"]);
            $this->actingAs($this->recebimento)->post(route('notas.store'), [
                'numero_nota' => "L{$loja}", 'fornecedor_id' => $forn->id,
                'loja' => $loja, 'origem' => 'recebimento',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertDatabaseHas('notas', ['numero_nota' => "L{$loja}", 'loja' => $loja]);
        }

        $this->assertSame([1, 2, 3, 7, 9, 11, 12, 13, 18], Nota::LOJAS);
    }

    public function test_loja_inexistente_recusada(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FX']);
        $this->actingAs($this->recebimento)->post(route('notas.store'), [
            'numero_nota' => 'LX', 'fornecedor_id' => $forn->id,
            'loja' => 99, 'origem' => 'recebimento',
        ])->assertSessionHasErrors('loja');
    }

    // ── Card "Reconferir" é exclusivo de CEASA ────────────────────────────────

    public function test_card_reconferir_em_nota_ceasa(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'HORTI']);
        $nota = Nota::create([
            'numero_nota' => '1212', 'fornecedor_id' => $forn->id,
            'user_id' => $this->preLote->id, 'loja' => 1, 'origem' => 'recebimento', 'ceasa' => 1,
        ]);

        // compras abre em nota CEASA
        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'reconferir'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => 'reconferir']);
    }

    public function test_card_reconferir_recusado_fora_do_ceasa(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'COMUM']);
        $nota = Nota::create([
            'numero_nota' => '1313', 'fornecedor_id' => $forn->id,
            'user_id' => $this->preLote->id, 'loja' => 1, 'origem' => 'recebimento', 'ceasa' => 0,
        ]);

        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'reconferir'])
            ->assertSessionHasErrors('tipo');

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_pre_lote_resolve_o_reconferir(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'HORTI']);
        $nota = Nota::create([
            'numero_nota' => '1414', 'fornecedor_id' => $forn->id,
            'user_id' => $this->preLote->id, 'loja' => 1, 'origem' => 'recebimento', 'ceasa' => 2,
        ]);
        $card = $nota->cards()->create([
            'tipo' => 'reconferir', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->compras->id,
        ]);

        $this->actingAs($this->preLote)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }
}
