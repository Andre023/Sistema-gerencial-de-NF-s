<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card de BLUESOFT — o mesmo comportamento do card de Regra.
 *
 * Não tem permissão nem aviso próprios: mora em Card::TIPOS_COMPRAS_ABRE junto
 * da regra. O que este arquivo protege é que ele continue andando junto dela:
 *   • compras e pré-lote abrem; recebimento e visitante não
 *   • quem resolve é o pré-lote, não compras
 *   • aberto por compras, o sino avisa o pré-lote (e só ele)
 */
class CardBluesoftTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $compras;
    private User $preLote;
    private User $visitante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->visitante   = User::factory()->create(['role' => User::ROLE_VISITANTE]);
    }

    private function nota(): Nota
    {
        return Nota::create([
            'numero_nota'   => (string) random_int(10000, 99999),
            'fornecedor_id' => Fornecedor::firstOrCreate(['nome' => 'FORN'])->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
            'ceasa'         => 0,
        ]);
    }

    public function test_anda_nas_mesmas_listas_que_a_regra(): void
    {
        $this->assertContains('bluesoft', Card::TIPOS);
        $this->assertContains('bluesoft', Card::abertosPorCompras());
        $this->assertContains('bluesoft', Card::TIPOS_AVISAM_PRE_LOTE);

        $this->assertNotContains('bluesoft', Card::TIPOS_COMPRAS);
        $this->assertNotContains('bluesoft', Card::abertosPeloRecebimento());
    }

    public function test_compras_e_pre_lote_abrem(): void
    {
        foreach ([$this->compras, $this->preLote] as $pessoa) {
            $nota = $this->nota();

            $this->actingAs($pessoa)
                ->post(route('notas.cards.store', $nota), ['tipo' => 'bluesoft'])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('cards', [
                'nota_id'    => $nota->id,
                'tipo'       => 'bluesoft',
                'aberto_por' => $pessoa->id,
            ]);
        }
    }

    public function test_recebimento_e_visitante_nao_abrem(): void
    {
        foreach ([$this->recebimento, $this->visitante] as $pessoa) {
            $nota = $this->nota();

            $this->actingAs($pessoa)
                ->post(route('notas.cards.store', $nota), ['tipo' => 'bluesoft'])
                ->assertForbidden();

            $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => 'bluesoft']);
        }
    }

    public function test_quem_resolve_e_o_pre_lote(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'bluesoft']);

        $card = $nota->cards()->where('tipo', 'bluesoft')->firstOrFail();

        $this->assertFalse($card->podeSerCorrigidoPor($this->compras));

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertForbidden();

        $this->actingAs($this->preLote)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', ['id' => $card->id, 'status' => Card::STATUS_RESOLVIDO]);
    }

    public function test_aberto_por_compras_avisa_so_o_pre_lote(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'bluesoft'])
            ->assertSessionHasNoErrors();

        $aviso = Notificacao::where('user_id', $this->preLote->id)
            ->where('nota_id', $nota->id)
            ->where('tipo', Notificacao::TIPO_REGRA)
            ->firstOrFail();

        $this->assertSame(['bluesoft'], $aviso->dados['tipos']);

        $this->assertDatabaseMissing('notificacoes', ['user_id' => $this->recebimento->id, 'nota_id' => $nota->id]);
    }
}
