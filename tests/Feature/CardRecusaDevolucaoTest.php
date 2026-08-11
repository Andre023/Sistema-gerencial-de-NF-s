<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cards de Recusa e Devolução — a mercadoria não fica.
 *
 * Combinação que nenhum outro tipo tem: QUALQUER papel abre (compras costuma
 * descobrir primeiro, pelo fornecedor), mas fecha só quem está com a
 * mercadoria — recebimento e pré-lote. É a parte que erraria em silêncio:
 * compras conseguindo marcar "resolvido" sem ter visto a doca.
 */
class CardRecusaDevolucaoTest extends TestCase
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
        ]);
    }

    private function card(Nota $nota, string $tipo): Card
    {
        return $nota->cards()->create([
            'tipo' => $tipo, 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);
    }

    /** @return array<string> */
    public static function tipos(): array
    {
        return [['recusa'], ['devolucao']];
    }

    // ─── Existem e são oferecidos ─────────────────────────────────────────────

    public function test_os_dois_tipos_existem(): void
    {
        $this->assertContains('recusa', Card::TIPOS);
        $this->assertContains('devolucao', Card::TIPOS);
    }

    /** Não podem entrar em TIPOS_COMPRAS: o sino cobraria compras por algo que ela não fecha. */
    public function test_nao_entram_na_fila_de_compras(): void
    {
        $this->assertNotContains('recusa', Card::TIPOS_COMPRAS);
        $this->assertNotContains('devolucao', Card::TIPOS_COMPRAS);
    }

    // ─── ABRIR: qualquer papel operacional ────────────────────────────────────

    #[DataProvider('tipos')]
    public function test_os_tres_papeis_operacionais_abrem(string $tipo): void
    {
        foreach ([$this->recebimento, $this->preLote, $this->compras] as $user) {
            $nota = $this->nota();

            $this->actingAs($user)
                ->post(route('notas.cards.store', $nota), ['tipo' => $tipo])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('cards', ['nota_id' => $nota->id, 'tipo' => $tipo]);
        }
    }

    #[DataProvider('tipos')]
    public function test_visitante_nao_abre(string $tipo): void
    {
        $nota = $this->nota();

        $this->actingAs($this->visitante)
            ->post(route('notas.cards.store', $nota), ['tipo' => $tipo])
            ->assertForbidden();

        $this->assertDatabaseMissing('cards', ['nota_id' => $nota->id, 'tipo' => $tipo]);
    }

    // ─── FECHAR: só quem está com a mercadoria ────────────────────────────────

    #[DataProvider('tipos')]
    public function test_recebimento_fecha(string $tipo): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, $tipo);

        $this->actingAs($this->recebimento)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }

    #[DataProvider('tipos')]
    public function test_pre_lote_fecha(string $tipo): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, $tipo);

        $this->actingAs($this->preLote)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertRedirect();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }

    /**
     * O ponto do exercício: compras ABRE, mas não FECHA. Marcar resolvido daqui
     * seria afirmar que a carga saiu sem ter olhado para a doca.
     */
    #[DataProvider('tipos')]
    public function test_compras_nao_fecha(string $tipo): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, $tipo);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertForbidden();

        $this->assertSame(Card::STATUS_ABERTO, $card->fresh()->status);
    }

    #[DataProvider('tipos')]
    public function test_visitante_nao_fecha(string $tipo): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, $tipo);

        $this->actingAs($this->visitante)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertForbidden();

        $this->assertSame(Card::STATUS_ABERTO, $card->fresh()->status);
    }

    // ─── Efeito na nota ───────────────────────────────────────────────────────

    #[DataProvider('tipos')]
    public function test_card_aberto_impede_liberar_a_nota(string $tipo): void
    {
        $nota = $this->nota();
        $this->card($nota, $tipo);

        $this->actingAs($this->preLote)
            ->post(route('notas.liberar', $nota))
            ->assertSessionHasErrors('nota');

        $this->assertNull($nota->fresh()->liberada_em);
    }

    // ─── Regressão: os outros tipos não mudaram ───────────────────────────────

    /** Compras continua fechando o que é dela. */
    public function test_compras_continua_fechando_card_de_custo(): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, 'custo');

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }

    /** E compras continua fechando "Importar NF", que é de todo mundo. */
    public function test_compras_continua_fechando_importar_nf(): void
    {
        $nota = $this->nota();
        $card = $this->card($nota, 'importar_nf');

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
    }

    /** Recebimento continua SEM abrir card de custo (esse é do pré-lote). */
    public function test_recebimento_continua_sem_abrir_card_de_custo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->recebimento)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'custo'])
            ->assertForbidden();
    }

    // ─── Rótulo ───────────────────────────────────────────────────────────────

    /** "devolucao" viraria "Devolucao" sem cedilha nas Estatísticas e no Dossiê. */
    public function test_rotulo_de_devolucao_sai_acentuado(): void
    {
        $this->assertSame('Devolução', Card::rotulo('devolucao'));
        $this->assertSame('Recusa', Card::rotulo('recusa'));
        // O padrão dos demais continua igual — nada de rótulo mudou sem querer
        $this->assertSame('Sem pedido', Card::rotulo('sem_pedido'));
        $this->assertSame('Item n pedido', Card::rotulo('item_n_pedido'));
    }
}
