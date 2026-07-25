<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estorno da liberação: conferiram errado e liberaram, mas a nota segue com
 * erro. Pré-lote e recebimento tiram das liberadas e devolvem ao recebimento.
 */
class DevolverNotaTest extends TestCase
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

    private function liberada(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);

        return Nota::create(array_merge([
            'numero_nota'   => '5001',
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'pre_lote',
            'liberada_por'  => $this->preLote->id,
            'liberada_em'   => now(),
        ], $extra));
    }

    public function test_recebimento_devolve_nota_ao_recebimento(): void
    {
        $nota = $this->liberada();

        $this->actingAs($this->recebimento)
            ->post(route('notas.devolver', $nota))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $nota->refresh();
        $this->assertNull($nota->liberada_em);
        $this->assertNull($nota->liberada_por);
        $this->assertSame('recebimento', $nota->origem);
    }

    public function test_pre_lote_tambem_devolve(): void
    {
        $nota = $this->liberada();

        $this->actingAs($this->preLote)
            ->post(route('notas.devolver', $nota))
            ->assertRedirect();

        $this->assertNull($nota->fresh()->liberada_em);
    }

    public function test_compras_nao_devolve(): void
    {
        $nota = $this->liberada();

        $this->actingAs($this->compras)
            ->post(route('notas.devolver', $nota))
            ->assertForbidden();

        $this->assertNotNull($nota->fresh()->liberada_em); // continua liberada
    }

    public function test_nao_devolve_nota_que_nao_esta_liberada(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);
        $naFila = Nota::create([
            'numero_nota' => '9', 'fornecedor_id' => $forn->id,
            'user_id' => $this->recebimento->id, 'loja' => 1, 'origem' => 'recebimento',
        ]);

        $this->actingAs($this->recebimento)
            ->post(route('notas.devolver', $naFila))
            ->assertSessionHasErrors('nota');
    }

    public function test_devolver_limpa_recebida_em(): void
    {
        $nota = $this->liberada(['recebida_em' => now()]);

        $this->actingAs($this->recebimento)->post(route('notas.devolver', $nota))->assertRedirect();

        $this->assertNull($nota->fresh()->recebida_em);
    }

    public function test_devolvida_volta_para_a_fila_e_sai_das_liberadas(): void
    {
        $nota = $this->liberada();
        // Um card já resolvido: ao voltar, a nota fica em "reconferir"
        $nota->cards()->create([
            'tipo' => 'cadastro', 'status' => Card::STATUS_RESOLVIDO,
            'aberto_por' => $this->preLote->id, 'resolvido_por' => $this->preLote->id, 'resolvido_em' => now(),
        ]);

        $this->actingAs($this->recebimento)->post(route('notas.devolver', $nota))->assertRedirect();

        $this->actingAs($this->recebimento)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->has('recebimento', 1)
                ->where('recebimento.0.numero_nota', '5001')
                ->where('recebimento.0.status', Nota::STATUS_RECONFERIR)
                ->has('liberadas', 0)
            );
    }
}
