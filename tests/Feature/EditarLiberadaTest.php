<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editar uma nota JÁ LIBERADA, por campo/papel:
 *   • observação → recebimento, compras e pré-lote
 *   • lembrete CEASA → só recebimento
 */
class EditarLiberadaTest extends TestCase
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

    private function liberada(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORN']);
        return Nota::create(array_merge([
            'numero_nota'   => (string) random_int(1000, 9999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
            'liberada_por'  => $this->preLote->id,
            'liberada_em'   => now(),
        ], $extra));
    }

    public function test_recebimento_edita_observacao_e_ceasa(): void
    {
        $nota = $this->liberada(['observacao' => 'antiga', 'ceasa' => 0]);

        $this->actingAs($this->recebimento)
            ->patch(route('notas.editar-liberada', $nota), ['observacao' => 'nova', 'ceasa' => 2])
            ->assertRedirect();

        $nota->refresh();
        $this->assertSame('nova', $nota->observacao);
        $this->assertSame(2, $nota->ceasa);
    }

    public function test_compras_edita_observacao(): void
    {
        $nota = $this->liberada(['observacao' => 'x']);

        $this->actingAs($this->compras)
            ->patch(route('notas.editar-liberada', $nota), ['observacao' => 'compras mexeu'])
            ->assertRedirect();

        $this->assertSame('compras mexeu', $nota->fresh()->observacao);
    }

    public function test_pre_lote_edita_observacao(): void
    {
        $nota = $this->liberada();

        $this->actingAs($this->preLote)
            ->patch(route('notas.editar-liberada', $nota), ['observacao' => 'pl'])
            ->assertRedirect();

        $this->assertSame('pl', $nota->fresh()->observacao);
    }

    public function test_compras_nao_edita_ceasa(): void
    {
        $nota = $this->liberada(['ceasa' => 1]);

        $this->actingAs($this->compras)
            ->patch(route('notas.editar-liberada', $nota), ['ceasa' => 2])
            ->assertForbidden();

        $this->assertSame(1, $nota->fresh()->ceasa); // inalterado
    }

    public function test_pre_lote_nao_edita_ceasa(): void
    {
        $nota = $this->liberada(['ceasa' => 1]);

        $this->actingAs($this->preLote)
            ->patch(route('notas.editar-liberada', $nota), ['ceasa' => 3])
            ->assertForbidden();

        $this->assertSame(1, $nota->fresh()->ceasa);
    }

    public function test_endpoint_recusa_nota_nao_liberada(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORN']);
        $naoLib = Nota::create([
            'numero_nota' => '5000', 'fornecedor_id' => $forn->id,
            'user_id' => $this->recebimento->id, 'loja' => 1, 'origem' => 'recebimento',
        ]);

        $this->actingAs($this->recebimento)
            ->patch(route('notas.editar-liberada', $naoLib), ['observacao' => 'x'])
            ->assertSessionHasErrors('nota');
    }
}
