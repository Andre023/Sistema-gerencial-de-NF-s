<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recebimento passa a EDITAR notas; e a nota de CEASA libera o setor de compras
 * para abrir cards (nas demais, só o pré-lote abre).
 */
class CeasaEPermissoesTest extends TestCase
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
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);

        return Nota::create(array_merge([
            'numero_nota'   => (string) rand(1000, 99999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    // ── Edição pelo recebimento ───────────────────────────────────────────────

    public function test_recebimento_edita_nota(): void
    {
        $nota = $this->nota(['observacao' => 'antiga']);

        $this->actingAs($this->recebimento)
            ->patch(route('notas.update', $nota), ['observacao' => 'nova'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('nova', $nota->fresh()->observacao);
    }

    public function test_compras_nao_edita_nota(): void
    {
        $nota = $this->nota(['observacao' => 'antiga']);

        $this->actingAs($this->compras)
            ->patch(route('notas.update', $nota), ['observacao' => 'nova'])
            ->assertForbidden();

        $this->assertSame('antiga', $nota->fresh()->observacao);
    }

    // ── CEASA ─────────────────────────────────────────────────────────────────

    public function test_lanca_nota_marcada_como_ceasa(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'HORTIFRUTI']);

        $this->actingAs($this->recebimento)->post(route('notas.store'), [
            'numero_nota' => '7777', 'fornecedor_id' => $forn->id,
            'loja' => 1, 'origem' => 'recebimento', 'ceasa' => true,
        ])->assertRedirect();

        $this->assertTrue(Nota::where('numero_nota', '7777')->first()->ceasa);
    }

    public function test_nota_nasce_sem_ceasa_por_padrao(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'NORMAL']);

        $this->actingAs($this->recebimento)->post(route('notas.store'), [
            'numero_nota' => '8888', 'fornecedor_id' => $forn->id,
            'loja' => 1, 'origem' => 'recebimento',
        ])->assertRedirect();

        $this->assertFalse(Nota::where('numero_nota', '8888')->first()->ceasa);
    }

    public function test_compras_abre_card_em_nota_ceasa(): void
    {
        $nota = $this->nota(['ceasa' => true]);

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'custo'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => 'custo']);
    }

    public function test_compras_nao_abre_card_em_nota_comum(): void
    {
        $nota = $this->nota(['ceasa' => false]);

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'custo'])
            ->assertForbidden();

        $this->assertDatabaseCount('cards', 0);
    }

    public function test_pre_lote_abre_card_em_qualquer_nota(): void
    {
        $comum = $this->nota(['ceasa' => false]);
        $ceasa = $this->nota(['ceasa' => true]);

        $this->actingAs($this->preLote)->post(route('notas.cards.store', $comum), ['tipo' => 'cadastro'])->assertRedirect();
        $this->actingAs($this->preLote)->post(route('notas.cards.store', $ceasa), ['tipo' => 'cadastro'])->assertRedirect();

        $this->assertDatabaseCount('cards', 2);
    }
}
