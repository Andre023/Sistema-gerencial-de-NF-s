<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quem é avisado dos cards que COMPRAS abre mas NÃO fecha.
 *
 * Recusa, devolução e trocar nota têm uma assimetria que os outros não têm:
 * compras costuma descobrir primeiro (o fornecedor liga), mas quem resolve é
 * quem está com a mercadoria e o papel na mão — o pré-lote.
 *
 * Sem aviso, o card ficava aberto na tela esperando alguém passar os olhos por
 * acaso. E como não são tipos de compras, o motor de notificação passava direto
 * por eles: ninguém era avisado de nada.
 */
class NotificacaoCardDocaTest extends TestCase
{
    use RefreshDatabase;

    private User $compras;
    private User $preLoteA;
    private User $preLoteB;
    private User $recebimento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->preLoteA    = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->preLoteB    = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
    }

    private function nota(): Nota
    {
        return Nota::create([
            'numero_nota'   => (string) random_int(10000, 99999),
            'fornecedor_id' => Fornecedor::firstOrCreate(['nome' => 'FORN'])->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    /** @return array<array<string>> */
    public static function tiposQuePedemDoca(): array
    {
        return [['recusa'], ['devolucao']];
    }

    // ─── O aviso chega ────────────────────────────────────────────────────────

    #[DataProvider('tiposQuePedemDoca')]
    public function test_compras_abrindo_avisa_pre_lote_e_recebimento(string $tipo): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => $tipo])
            ->assertSessionHasNoErrors();

        // Os dois setores fecham estes cards — avisar só um deixaria o outro
        // esperando alguém passar os olhos por acaso.
        foreach ([$this->preLoteA, $this->preLoteB, $this->recebimento] as $pessoa) {
            $this->assertDatabaseHas('notificacoes', [
                'user_id' => $pessoa->id,
                'nota_id' => $nota->id,
                'tipo'    => Notificacao::TIPO_DOCA,
            ]);
        }
    }

    #[DataProvider('tiposQuePedemDoca')]
    public function test_o_aviso_diz_de_que_card_se_trata(string $tipo): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => $tipo]);

        $aviso = Notificacao::where('user_id', $this->preLoteA->id)->firstOrFail();

        $this->assertSame([$tipo], $aviso->dados['tipos']);
        $this->assertSame($this->compras->name, $aviso->dados['autor']);
    }

    public function test_dois_cards_de_doca_acumulam_no_mesmo_aviso(): void
    {
        // Mesma regra dos cards de compras: uma linha viva por nota, não uma
        // por card — senão a mesma nota vira três avisos no sino.
        $nota = $this->nota();

        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'recusa']);
        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'devolucao']);

        $avisos = Notificacao::where('user_id', $this->preLoteA->id)
            ->where('tipo', Notificacao::TIPO_DOCA)->viva()->get();

        $this->assertCount(1, $avisos);
        $this->assertEqualsCanonicalizing(['recusa', 'devolucao'], $avisos->first()->dados['tipos']);
    }

    // ─── Ninguém é avisado da própria ação ────────────────────────────────────

    public function test_pre_lote_abrindo_nao_avisa_a_si_mesmo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'recusa']);

        $this->assertDatabaseMissing('notificacoes', [
            'user_id' => $this->preLoteA->id,
            'tipo'    => Notificacao::TIPO_DOCA,
        ]);

        // O colega de setor e o recebimento continuam sendo avisados
        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $this->preLoteB->id,
            'tipo'    => Notificacao::TIPO_DOCA,
        ]);
        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $this->recebimento->id,
            'tipo'    => Notificacao::TIPO_DOCA,
        ]);
    }

    public function test_compras_nao_recebe_este_aviso(): void
    {
        // O card não é dela: cobrá-la por algo que ela não pode fechar só ensina
        // o comprador a ignorar o sino.
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'devolucao']);

        $this->assertDatabaseMissing('notificacoes', [
            'user_id' => $this->compras->id,
            'nota_id' => $nota->id,
        ]);
    }

    // ─── O aviso morre quando o motivo morre ──────────────────────────────────

    #[DataProvider('tiposQuePedemDoca')]
    public function test_resolver_o_card_encerra_o_aviso(string $tipo): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => $tipo]);

        $card = $nota->cards()->firstOrFail();

        $this->actingAs($this->preLoteA)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            Notificacao::where('nota_id', $nota->id)->where('tipo', Notificacao::TIPO_DOCA)->viva()->count(),
            'Card resolvido: o aviso tem de sumir do sino de todo mundo.',
        );
    }

    public function test_um_card_resolvido_nao_apaga_o_aviso_do_outro(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'recusa']);
        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'devolucao']);

        $recusa = $nota->cards()->where('tipo', 'recusa')->firstOrFail();

        $this->actingAs($this->preLoteA)->patch(route('notas.cards.resolver', [$nota, $recusa]));

        $vivo = Notificacao::where('nota_id', $nota->id)
            ->where('tipo', Notificacao::TIPO_DOCA)->viva()->first();

        $this->assertNotNull($vivo, 'Ainda há um card de doca aberto — o aviso continua.');
        $this->assertSame(['devolucao'], $vivo->dados['tipos']);
    }

    // ─── Não atropela o aviso de compras ──────────────────────────────────────

    public function test_card_de_doca_nao_apaga_o_aviso_de_compras(): void
    {
        /*
         * A armadilha desta mudança: os dois avisos convivem na MESMA nota.
         * Se o de doca reusasse o tipo 'divergencia', encerrar um encerraria o
         * outro — e compras perderia a cobrança de um card que continua aberto.
         */
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)->post(route('notas.cards.store', $nota), ['tipo' => 'custo']);
        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'recusa']);

        $recusa = $nota->cards()->where('tipo', 'recusa')->firstOrFail();
        $this->actingAs($this->preLoteA)->patch(route('notas.cards.resolver', [$nota, $recusa]));

        // O card de custo segue aberto: compras continua com o aviso dela
        $this->assertDatabaseHas('notificacoes', [
            'user_id'      => $this->compras->id,
            'nota_id'      => $nota->id,
            'tipo'         => Notificacao::TIPO_DIVERGENCIA,
            'encerrada_em' => null,
        ]);
    }

    public function test_resolver_o_de_compras_nao_apaga_o_de_doca(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)->post(route('notas.cards.store', $nota), ['tipo' => 'custo']);
        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'devolucao']);

        $custo = $nota->cards()->where('tipo', 'custo')->firstOrFail();
        $this->actingAs($this->compras)->patch(route('notas.cards.corrigir', [$nota, $custo]));

        $this->assertDatabaseHas('notificacoes', [
            'user_id'      => $this->preLoteB->id,
            'nota_id'      => $nota->id,
            'tipo'         => Notificacao::TIPO_DOCA,
            'encerrada_em' => null,
        ]);
    }
}
