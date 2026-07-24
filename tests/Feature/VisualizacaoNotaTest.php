<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A reserva "estou olhando esta nota" (o 🙋‍♂️): um dono por vez, para dois não
 * pegarem a mesma nota ao mesmo tempo. Some sozinha quando a pessoa age.
 */
class VisualizacaoNotaTest extends TestCase
{
    use RefreshDatabase;

    private User $paloma;
    private User $larissa;
    private User $compras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paloma  = User::factory()->create(['name' => 'Paloma Silva', 'role' => User::ROLE_PRE_LOTE]);
        $this->larissa = User::factory()->create(['name' => 'Larissa Souza', 'role' => User::ROLE_PRE_LOTE]);
        $this->compras = User::factory()->create(['name' => 'Andre Lima', 'role' => User::ROLE_COMPRAS]);
    }

    private function nota(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);

        return Nota::create(array_merge([
            'numero_nota'   => '5342',
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->paloma->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    // ── Reservar / soltar ─────────────────────────────────────────────────────

    public function test_reivindica_a_reserva(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->paloma)
            ->post(route('notas.visualizar', $nota))
            ->assertRedirect();

        $this->assertSame($this->paloma->id, $nota->fresh()->visualizando_por);
        $this->assertNotNull($nota->fresh()->visualizando_em);
    }

    public function test_dono_clicando_de_novo_solta_a_reserva(): void
    {
        $nota = $this->nota(['visualizando_por' => $this->paloma->id, 'visualizando_em' => now()]);

        $this->actingAs($this->paloma)
            ->post(route('notas.visualizar', $nota))
            ->assertRedirect();

        $this->assertNull($nota->fresh()->visualizando_por);
    }

    public function test_outra_pessoa_recebe_aviso_e_nao_toma_a_reserva(): void
    {
        $nota = $this->nota(['visualizando_por' => $this->paloma->id, 'visualizando_em' => now()]);

        $this->actingAs($this->larissa)
            ->post(route('notas.visualizar', $nota))
            ->assertRedirect()
            ->assertSessionHas('erro', 'Paloma está olhando esta nota.');

        // A reserva continua da Paloma
        $this->assertSame($this->paloma->id, $nota->fresh()->visualizando_por);
    }

    // ── Some sozinha quando a pessoa age ──────────────────────────────────────

    public function test_abrir_card_solta_a_reserva(): void
    {
        $nota = $this->nota(['visualizando_por' => $this->paloma->id, 'visualizando_em' => now()]);

        $this->actingAs($this->paloma)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro'])
            ->assertRedirect();

        $this->assertNull($nota->fresh()->visualizando_por);
    }

    public function test_corrigir_card_solta_a_reserva(): void
    {
        $nota = $this->nota(['visualizando_por' => $this->compras->id, 'visualizando_em' => now()]);
        $card = $nota->cards()->create(['tipo' => 'custo', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->paloma->id]);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect();

        $this->assertNull($nota->fresh()->visualizando_por);
    }

    public function test_liberar_solta_a_reserva(): void
    {
        $nota = $this->nota(['visualizando_por' => $this->paloma->id, 'visualizando_em' => now()]);

        $this->actingAs($this->paloma)
            ->post(route('notas.liberar', $nota))
            ->assertRedirect();

        $nota->refresh();
        $this->assertNotNull($nota->liberada_em);
        $this->assertNull($nota->visualizando_por);
    }

    // ── Exposição para a tela ─────────────────────────────────────────────────

    public function test_index_expoe_quem_esta_olhando(): void
    {
        $this->nota(['visualizando_por' => $this->paloma->id, 'visualizando_em' => now()]);

        $this->actingAs($this->larissa)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('recebimento.0.visualizando_por.id', $this->paloma->id)
                ->where('recebimento.0.visualizando_por.name', 'Paloma Silva')
            );
    }
}
