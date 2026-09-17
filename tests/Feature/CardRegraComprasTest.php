<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compras abrindo card de REGRA.
 *
 * Quem negocia a regra da compra com o fornecedor é compras — e é ela quem
 * sabe primeiro que a nota vai chegar fora do combinado. Antes disto, compras
 * tinha de pedir ao pré-lote que abrisse o card, para o próprio pré-lote
 * resolver depois: um repasse de recado a mais no caminho.
 *
 * O que este arquivo protege é a FRONTEIRA da permissão, estreita como a do
 * cadastro (CardCadastroRecebimentoTest):
 *   • compras abre a regra, e só ela ganhou — o recebimento não
 *   • compras não passou a gerir cards: não resolve, reabre nem exclui
 *   • quem RESOLVE a regra continua sendo o pré-lote
 */
class CardRegraComprasTest extends TestCase
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
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
            'ceasa'         => 0,
        ]);
    }

    // ─── Quem abre ─────────────────────────────────────────────────────────────

    public function test_compras_abre_card_de_regra_em_nota_comum(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', [
            'nota_id'    => $nota->id,
            'tipo'       => 'regra',
            'aberto_por' => $this->compras->id,
        ]);
    }

    public function test_pre_lote_continua_abrindo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => 'regra']);
    }

    // ─── A fronteira: compras ganhou UM card, não o pacote ─────────────────────

    public function test_recebimento_nao_ganhou_a_regra(): void
    {
        /*
         * A regra é conversa entre compras e o fornecedor. Quem está na doca
         * não tem como saber o que foi combinado — abrir esse card de lá
         * seria chute.
         */
        $nota = $this->nota();

        $this->actingAs($this->recebimento)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra'])
            ->assertForbidden();

        $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => 'regra']);
    }

    public function test_visitante_nao_abre(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->visitante)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra'])
            ->assertForbidden();

        $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => 'regra']);
    }

    public function test_compras_continua_sem_os_outros_cards_fora_de_ceasa(): void
    {
        // Abrir a regra não abriu a porteira: custo, quantidade e afins seguem
        // sendo abertos pelo pré-lote, que é quem confere a nota.
        foreach (['custo', 'quantidade', 'sem_pedido', 'item_n_pedido'] as $tipo) {
            $nota = $this->nota();

            $this->actingAs($this->compras)
                ->post(route('notas.cards.store', $nota), ['tipo' => $tipo])
                ->assertForbidden();

            $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => $tipo]);
        }
    }

    public function test_compras_nao_passou_a_gerir_cards(): void
    {
        $this->assertTrue($this->compras->podeAbrirCardDeRegra());
        $this->assertFalse($this->compras->podeGerirCards());
    }

    // ─── Quem resolve continua sendo o pré-lote ────────────────────────────────

    public function test_a_regra_aberta_por_compras_nao_e_corrigida_por_compras(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);

        $card = $nota->cards()->where('tipo', 'regra')->firstOrFail();

        // Se compras abrisse e fechasse, o card não passaria pelo pré-lote —
        // e é ele quem confere se a regra foi acertada.
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

    // ─── A lista que a tela recebe ─────────────────────────────────────────────

    public function test_a_lista_de_compras_tem_a_regra_alem_das_do_recebimento(): void
    {
        $lista = Card::abertosPorCompras();

        $this->assertContains('regra', $lista);

        // E não perdeu nenhum dos que já eram dela (inclusive o Cadastro)
        foreach (Card::abertosPeloRecebimento() as $tipo) {
            $this->assertContains($tipo, $lista);
        }
    }

    public function test_as_outras_listas_nao_ganharam_a_regra(): void
    {
        // 'regra' é regida por User::podeAbrirCardDeRegra(). Se entrasse nas
        // listas gerais, o recebimento e o visitante a ganhariam sem passar
        // pela permissão.
        $this->assertNotContains('regra', Card::abertosPorQualquerPapel());
        $this->assertNotContains('regra', Card::abertosPeloRecebimento());
    }

    public function test_a_tela_recebe_a_lista_de_compras(): void
    {
        $this->actingAs($this->compras)
            ->get(route('notas.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->where('opcoes.tiposComprasAbre', Card::abertosPorCompras())
                ->where('auth.can.abrirCardRegra', true));
    }
}
