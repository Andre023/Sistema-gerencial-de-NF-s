<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card "Importar NF": recebimento, pré-lote e compras ABREM e marcam como feito.
 * Não é um erro de um setor só. Os cards normais seguem compras-only (regressão).
 */
class CardImportarNfTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $compras;
    private User $preLote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
    }

    private function nota(): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORN']);
        return Nota::create([
            'numero_nota'   => (string) random_int(1000, 9999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    private function card(Nota $nota, string $tipo): Card
    {
        return $nota->cards()->create([
            'tipo' => $tipo, 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);
    }

    // ── Abrir ──
    public function test_recebimento_abre_importar_nf(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->recebimento)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'importar_nf'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => 'importar_nf']);
    }

    public function test_compras_abre_importar_nf_em_nota_comum(): void
    {
        $nota = $this->nota(); // ceasa = 0
        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'importar_nf'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => 'importar_nf']);
    }

    // ── Marcar como feito (corrigir) ──
    public function test_recebimento_corrige_importar_nf(): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, 'importar_nf');
        $this->actingAs($this->recebimento)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }

    public function test_compras_corrige_importar_nf(): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, 'importar_nf');
        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }

    // ── Regressão: cards normais continuam compras-only ──
    public function test_recebimento_nao_corrige_card_normal(): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, 'custo');
        $this->actingAs($this->recebimento)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertForbidden();
        $this->assertSame(Card::STATUS_ABERTO, $card->fresh()->status);
    }

    public function test_compras_corrige_card_normal_ainda_funciona(): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, 'custo');
        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }

    public function test_recebimento_nao_abre_card_normal(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->recebimento)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'custo'])
            ->assertForbidden();
    }

    // ── "Trocar nota" saiu de cena ─────────────────────────────────

    /**
     * O tipo foi unificado com a Recusa (migration
     * cards_trocar_nota_viram_recusa). Este teste existe para que ele não
     * volte por descuido: a lista de TIPOS é escrita à mão em mais de um
     * lugar, e um "trocar_nota" reaparecendo no controller sem estar na tela
     * seria um card que ninguém consegue abrir — ou pior, um que abre e a
     * tela mostra cru.
     */
    public function test_trocar_nota_nao_existe_mais(): void
    {
        $this->assertNotContains('trocar_nota', Card::TIPOS);

        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'trocar_nota'])
            ->assertSessionHasErrors('tipo');

        $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => 'trocar_nota']);
    }
}
