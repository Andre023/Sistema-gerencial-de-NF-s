<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O recebimento abrindo card de CADASTRO.
 *
 * O item sem cadastro no ERP aparece na hora de digitar a nota, com o caminhão
 * na porta — quem esbarra nele é o recebimento, não o pré-lote, que só olha
 * depois. Antes disto o recebimento tinha de pedir ao pré-lote que abrisse o
 * card por ele, e a pendência ficava esperando um repasse de recado.
 *
 * O que este arquivo protege é a FRONTEIRA da permissão, que é estreita de
 * propósito:
 *   • o recebimento abre o cadastro, e só ele — não ganhou os outros cards
 *     de compras (custo, quantidade…) nem passou a gerir cards
 *   • quem CORRIGE o cadastro continua sendo compras: abrir e fechar no mesmo
 *     setor tiraria o sentido de existir o card
 */
class CardCadastroRecebimentoTest extends TestCase
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

    private function nota(bool $ceasa = false): Nota
    {
        return Nota::create([
            'numero_nota'   => (string) random_int(10000, 99999),
            'fornecedor_id' => Fornecedor::firstOrCreate(['nome' => 'FORN'])->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
            'ceasa'         => $ceasa ? 1 : 0,
        ]);
    }

    // ─── Quem abre ─────────────────────────────────────────────────────────────

    public function test_recebimento_abre_card_de_cadastro(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->recebimento)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', [
            'nota_id'    => $nota->id,
            'tipo'       => 'cadastro',
            'aberto_por' => $this->recebimento->id,
        ]);
    }

    public function test_pre_lote_continua_abrindo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => 'cadastro']);
    }

    public function test_visitante_nao_abre(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->visitante)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro'])
            ->assertForbidden();

        $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => 'cadastro']);
    }

    // ─── A fronteira: o recebimento ganhou UM card, não o pacote ───────────────

    public function test_recebimento_continua_sem_os_outros_cards_de_compras(): void
    {
        /*
         * Este é o teste que impede a permissão de vazar. "Cadastro" saiu do
         * bloco de compras para o recebimento por um motivo específico (quem vê
         * o item sem cadastro é quem digita a nota) — e esse motivo não vale
         * para custo nem quantidade, que são conferência, não digitação.
         */
        foreach (['custo', 'quantidade', 'sem_pedido', 'item_n_pedido'] as $tipo) {
            $nota = $this->nota();

            $this->actingAs($this->recebimento)
                ->post(route('notas.cards.store', $nota), ['tipo' => $tipo])
                ->assertForbidden();

            $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => $tipo]);
        }
    }

    public function test_recebimento_nao_passou_a_gerir_cards(): void
    {
        // Abrir o cadastro não é o mesmo que resolver, reabrir ou excluir card.
        $this->assertTrue($this->recebimento->podeAbrirCardDeCadastro());
        $this->assertFalse($this->recebimento->podeGerirCards());
    }

    // ─── Quem corrige continua sendo compras ───────────────────────────────────

    public function test_o_cadastro_aberto_pelo_recebimento_e_corrigido_por_compras(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->recebimento)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro']);

        $card = $nota->cards()->where('tipo', 'cadastro')->firstOrFail();

        /*
         * A razão de o card existir é passar o problema adiante. Se quem abre
         * também fechasse, o recebimento poderia dar o cadastro por resolvido
         * sem ninguém ter mexido no ERP.
         */
        $this->assertFalse($card->podeSerCorrigidoPor($this->recebimento));
        $this->assertTrue($card->podeSerCorrigidoPor($this->compras));
    }

    // ─── O que não mudou para compras ──────────────────────────────────────────

    public function test_compras_continua_sem_abrir_cadastro_em_nota_comum(): void
    {
        $nota = $this->nota();

        // Compras é quem CORRIGE o cadastro. Abrir para si mesma tiraria o
        // sentido do card — por isso 'cadastro' ficou fora de
        // abertosPorQualquerPapel() e ganhou uma lista só do recebimento.
        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro'])
            ->assertForbidden();
    }

    public function test_compras_ainda_abre_em_nota_de_ceasa(): void
    {
        $nota = $this->nota(ceasa: true);

        // Regra antiga, intocada: em CEASA compras abre qualquer tipo.
        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => 'cadastro']);
    }

    // ─── A lista que a tela recebe ─────────────────────────────────────────────

    public function test_a_lista_do_recebimento_tem_o_cadastro_alem_dos_de_todos(): void
    {
        $lista = Card::abertosPeloRecebimento();

        $this->assertContains('cadastro', $lista);

        // E não perdeu nenhum dos que já eram dele
        foreach (Card::abertosPorQualquerPapel() as $tipo) {
            $this->assertContains($tipo, $lista);
        }
    }

    public function test_a_lista_de_qualquer_papel_nao_ganhou_o_cadastro(): void
    {
        /*
         * Se 'cadastro' entrasse aqui, compras passaria a abri-lo em qualquer
         * nota — que é justamente o que o teste de cima proíbe. As duas listas
         * existem separadas por isto.
         */
        $this->assertNotContains('cadastro', Card::abertosPorQualquerPapel());
    }

    public function test_a_tela_recebe_a_lista_do_recebimento(): void
    {
        $this->actingAs($this->recebimento)
            ->get(route('notas.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->where('opcoes.tiposRecebimento', Card::abertosPeloRecebimento()));
    }
}
