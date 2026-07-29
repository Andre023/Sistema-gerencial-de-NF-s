<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelamento (o fornecedor cancelou a NF): a nota sai da fila e vai para
 * "Canceladas neste dia", SEM ser excluída — o histórico fica para as
 * estatísticas (docs/NOTAS_CANCELADAS.md). Pré-lote e compras cancelam.
 */
class CancelamentoNotaTest extends TestCase
{
    use RefreshDatabase;

    private User $preLote;
    private User $compras;
    private User $recebimento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
    }

    private function nota(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORN']);
        return Nota::create(array_merge([
            'numero_nota'   => (string) random_int(1000, 9999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    public function test_pre_lote_cancela_com_motivo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.cancelar', $nota), ['motivo' => 'fornecedor cancelou'])
            ->assertRedirect();

        $nota->refresh();
        $this->assertNotNull($nota->cancelada_em);
        $this->assertSame($this->preLote->id, $nota->cancelada_por);
        $this->assertSame('fornecedor cancelou', $nota->motivo_cancelamento);
        $this->assertSame(Nota::STATUS_CANCELADA, $nota->statusCalculado());
    }

    public function test_compras_cancela(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cancelar', $nota))
            ->assertRedirect();

        $this->assertNotNull($nota->fresh()->cancelada_em);
    }

    public function test_recebimento_nao_cancela(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->recebimento)
            ->post(route('notas.cancelar', $nota))
            ->assertForbidden();

        $this->assertNull($nota->fresh()->cancelada_em);
    }

    public function test_cancelada_sai_da_fila_e_entra_em_canceladas(): void
    {
        $nota = $this->nota(['numero_nota' => 'CANC1']);
        $this->nota(['numero_nota' => 'ATIVA']);

        $this->actingAs($this->preLote)->post(route('notas.cancelar', $nota));

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->has('recebimento', 1)          // só a ATIVA continua na fila
                ->where('recebimento.0.numero_nota', 'ATIVA')
                ->has('canceladas', 1)
                ->where('canceladas.0.numero_nota', 'CANC1')
            );
    }

    public function test_nota_cancelada_nao_e_excluida(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->preLote)->post(route('notas.cancelar', $nota));

        // continua na tabela, sem soft delete
        $this->assertDatabaseHas('notas', ['id' => $nota->id, 'deleted_at' => null]);
    }

    public function test_descancelar_devolve_a_fila(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->preLote)->post(route('notas.cancelar', $nota), ['motivo' => 'engano']);

        $this->actingAs($this->preLote)
            ->post(route('notas.descancelar', $nota))
            ->assertRedirect();

        $nota->refresh();
        $this->assertNull($nota->cancelada_em);
        $this->assertNull($nota->cancelada_por);
        $this->assertNull($nota->motivo_cancelamento);
    }

    public function test_nao_cancela_duas_vezes(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->preLote)->post(route('notas.cancelar', $nota));

        $this->actingAs($this->preLote)
            ->post(route('notas.cancelar', $nota))
            ->assertSessionHasErrors('nota');
    }

    public function test_cancelada_liberada_sai_das_liberadas(): void
    {
        $nota = $this->nota(['liberada_por' => $this->preLote->id, 'liberada_em' => now()]);

        $this->actingAs($this->preLote)->post(route('notas.cancelar', $nota));

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page->has('liberadas', 0)->has('canceladas', 1));
    }
}
