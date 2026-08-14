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
 * Corrigir o card de CADASTRO troca ele por outro — nunca sai só resolvido.
 *
 * O item que não existia passa a existir, mas existir não é estar no pedido:
 * ou não há pedido nenhum (Sem pedido), ou há e o item não está nele (Item n
 * pedido). Antes, corrigir o cadastro fechava a nota como se estivesse tudo
 * certo, e a pendência real só aparecia quando alguém tropeçava nela.
 */
class CardCadastroTrocaTest extends TestCase
{
    use RefreshDatabase;

    private User $compras;
    private User $preLote;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compras = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->admin   = User::factory()->create(['role' => User::ROLE_ADMIN]);
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

    private function cardCadastro(Nota $nota): Card
    {
        return $nota->cards()->create([
            'tipo' => 'cadastro', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);
    }

    // ─── A troca acontece ─────────────────────────────────────────────────────

    /** @return array<array<string>> */
    public static function substitutos(): array
    {
        return [['item_n_pedido'], ['sem_pedido']];
    }

    #[DataProvider('substitutos')]
    public function test_corrigir_cadastro_abre_o_card_escolhido(string $substituto): void
    {
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]), ['substituto' => $substituto])
            ->assertSessionHasNoErrors();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);

        $this->assertDatabaseHas('cards', [
            'nota_id' => $nota->id,
            'tipo'    => $substituto,
            'status'  => Card::STATUS_ABERTO,
            // Quem trocou é quem abriu o novo — o histórico não pode dizer que
            // o card apareceu sozinho.
            'aberto_por' => $this->compras->id,
        ]);
    }

    #[DataProvider('substitutos')]
    public function test_a_nota_continua_travada_depois_da_troca(string $substituto): void
    {
        // O ponto de existir a troca: corrigir cadastro não pode liberar a nota,
        // porque a pendência apenas MUDOU de nome.
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]), ['substituto' => $substituto]);

        $this->actingAs($this->preLote)
            ->post(route('notas.liberar', $nota))
            ->assertSessionHasErrors('nota');

        $this->assertNull($nota->fresh()->liberada_em);
    }

    #[DataProvider('substitutos')]
    public function test_a_nota_segue_com_divergencia_e_nao_vai_para_reconferir(string $substituto): void
    {
        /*
         * A contrapartida do FluxoNotaTest: corrigir o ÚNICO card normalmente
         * deixa a nota em "reconferir". Com o cadastro não: como a correção
         * abre outro card, a nota continua "com divergência".
         *
         * É a diferença entre "acabou, é só conferir" e "mudou de assunto".
         */
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]), ['substituto' => $substituto]);

        $this->assertSame(
            Nota::STATUS_DIVERGENCIA,
            $nota->fresh()->load('cards')->statusCalculado(),
        );
    }

    // ─── Sem escolha não passa ────────────────────────────────────────────────

    public function test_corrigir_cadastro_sem_escolher_e_recusado(): void
    {
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertSessionHasErrors('substituto');

        // Nada mudou: o card continua aberto e nenhum card novo entrou
        $this->assertSame(Card::STATUS_ABERTO, $card->fresh()->status);
        $this->assertSame(1, $nota->cards()->count());
    }

    public function test_so_aceita_os_dois_tipos_combinados(): void
    {
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        foreach (['custo', 'regra', 'cadastro', 'recusa'] as $invalido) {
            $this->actingAs($this->compras)
                ->patch(route('notas.cards.corrigir', [$nota, $card]), ['substituto' => $invalido])
                ->assertSessionHasErrors('substituto');
        }

        $this->assertSame(Card::STATUS_ABERTO, $card->fresh()->status);
        $this->assertSame(1, $nota->cards()->count());
    }

    public function test_a_regra_vale_tambem_para_o_admin(): void
    {
        // Admin corrige qualquer card — mas a troca é da natureza do cadastro,
        // não um limite de permissão.
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $this->actingAs($this->admin)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertSessionHasErrors('substituto');

        $this->assertSame(Card::STATUS_ABERTO, $card->fresh()->status);
    }

    // ─── Não duplica ──────────────────────────────────────────────────────────

    public function test_se_o_substituto_ja_estiver_aberto_nao_duplica(): void
    {
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $nota->cards()->create([
            'tipo' => 'sem_pedido', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]), ['substituto' => 'sem_pedido'])
            ->assertSessionHasNoErrors();

        // O cadastro se resolve; a pendência que já existia continua valendo por si
        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
        $this->assertSame(1, $nota->cards()->where('tipo', 'sem_pedido')->count());
    }

    // ─── O aviso segue o card novo ────────────────────────────────────────────

    public function test_a_troca_avisa_quem_cuida_do_card_novo(): void
    {
        // Sem isto, o card novo nasceria mudo: compras corrigiria o cadastro e
        // ninguém saberia que sobrou pendência.
        $outroComprador = User::factory()->create(['role' => User::ROLE_COMPRAS]);

        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]), ['substituto' => 'item_n_pedido']);

        $aviso = Notificacao::where('user_id', $outroComprador->id)
            ->where('nota_id', $nota->id)
            ->where('tipo', Notificacao::TIPO_DIVERGENCIA)
            ->viva()
            ->first();

        $this->assertNotNull($aviso, 'O card trocado precisa acender o sino de compras.');
        $this->assertSame(['item_n_pedido'], $aviso->dados['tipos']);
    }

    public function test_a_tela_recebe_a_lista_de_substitutos(): void
    {
        /*
         * O controller aceitar não basta: os botões da troca são montados a
         * partir de `opcoes.substitutosCadastro`. Enquanto a lista de "quem abre
         * o quê" viveu duplicada no frontend, Recusa e Devolução eram aceitas
         * pelo backend e invisíveis na tela — o mesmo erro cabe aqui.
         */
        $this->actingAs($this->compras)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('opcoes.substitutosCadastro', Card::SUBSTITUTOS_DE_CADASTRO));
    }

    // ─── Os outros tipos não mudaram ──────────────────────────────────────────

    /** @return array<array<string>> */
    public static function outrosDeCompras(): array
    {
        return [['custo'], ['quantidade'], ['sem_pedido'], ['item_n_pedido']];
    }

    #[DataProvider('outrosDeCompras')]
    public function test_os_outros_cards_de_compras_corrigem_sem_troca(string $tipo): void
    {
        $nota = $this->nota();
        $card = $nota->cards()->create([
            'tipo' => $tipo, 'status' => Card::STATUS_ABERTO, 'aberto_por' => $this->preLote->id,
        ]);

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertSessionHasNoErrors();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
        $this->assertSame(1, $nota->cards()->count(), 'Nenhum card novo devia ter nascido.');
    }

    public function test_pre_lote_resolvendo_cadastro_direto_nao_exige_troca(): void
    {
        /*
         * "Resolver" é outra porta: o pré-lote fecha o card que abriu por engano,
         * ou que se acertou sozinho. Exigir a troca aqui obrigaria a inventar uma
         * pendência para desfazer um erro de digitação.
         */
        $nota = $this->nota();
        $card = $this->cardCadastro($nota);

        $this->actingAs($this->preLote)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertSessionHasNoErrors();

        $this->assertSame(Card::STATUS_RESOLVIDO, $card->fresh()->status);
        $this->assertSame(1, $nota->cards()->count());
    }
}
