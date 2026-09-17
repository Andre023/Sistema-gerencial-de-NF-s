<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quem é avisado do card de REGRA que compras abre.
 *
 * Enquanto só o pré-lote abria a regra, não havia a quem avisar: quem abria
 * era quem resolvia. Com compras abrindo (User::podeAbrirCardDeRegra), o
 * card nascia sem ninguém saber — o motor só olhava para TIPOS_COMPRAS e para
 * a doca, e a regra não está em nenhum dos dois.
 *
 * Irmão do NotificacaoCardDocaTest: mesmo problema, destinatário diferente.
 * Aqui só o pré-lote é avisado — o recebimento não resolve regra.
 */
class NotificacaoCardRegraTest extends TestCase
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

    // ─── O aviso chega ────────────────────────────────────────────────────────

    public function test_compras_abrindo_avisa_o_pre_lote(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra'])
            ->assertSessionHasNoErrors();

        foreach ([$this->preLoteA, $this->preLoteB] as $pessoa) {
            $this->assertDatabaseHas('notificacoes', [
                'user_id' => $pessoa->id,
                'nota_id' => $nota->id,
                'tipo'    => Notificacao::TIPO_REGRA,
            ]);
        }

        $aviso = Notificacao::where('user_id', $this->preLoteA->id)->firstOrFail();

        $this->assertSame(['regra'], $aviso->dados['tipos']);
        $this->assertSame($this->compras->name, $aviso->dados['autor']);
    }

    public function test_o_recebimento_nao_recebe_este_aviso(): void
    {
        // O recebimento não resolve regra — cobrá-lo por ela seria o mesmo que
        // cobrar compras pela recusa: só ensina a ignorar o sino.
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);

        $this->assertDatabaseMissing('notificacoes', [
            'user_id' => $this->recebimento->id,
            'nota_id' => $nota->id,
        ]);
    }

    public function test_compras_nao_recebe_este_aviso(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);

        $this->assertDatabaseMissing('notificacoes', [
            'user_id' => $this->compras->id,
            'nota_id' => $nota->id,
        ]);
    }

    // ─── Ninguém é avisado da própria ação ────────────────────────────────────

    public function test_pre_lote_abrindo_nao_avisa_a_si_mesmo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);

        $this->assertDatabaseMissing('notificacoes', [
            'user_id' => $this->preLoteA->id,
            'tipo'    => Notificacao::TIPO_REGRA,
        ]);

        // O colega de setor continua sendo avisado
        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $this->preLoteB->id,
            'tipo'    => Notificacao::TIPO_REGRA,
        ]);
    }

    // ─── O aviso morre quando o motivo morre ──────────────────────────────────

    public function test_resolver_o_card_encerra_o_aviso(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);

        $card = $nota->cards()->firstOrFail();

        $this->actingAs($this->preLoteA)
            ->patch(route('notas.cards.resolver', [$nota, $card]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            Notificacao::where('nota_id', $nota->id)->where('tipo', Notificacao::TIPO_REGRA)->viva()->count(),
            'Card resolvido: o aviso tem de sumir do sino de todo mundo.',
        );
    }

    public function test_reabrir_o_card_avisa_de_novo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);
        $card = $nota->cards()->firstOrFail();

        $this->actingAs($this->preLoteA)->patch(route('notas.cards.resolver', [$nota, $card]));
        $this->actingAs($this->preLoteA)->patch(route('notas.cards.reabrir', [$nota, $card]));

        // Reaberta pelo A: o B precisa saber que a regra voltou a estar pendente
        $this->assertDatabaseHas('notificacoes', [
            'user_id'      => $this->preLoteB->id,
            'nota_id'      => $nota->id,
            'tipo'         => Notificacao::TIPO_REGRA,
            'encerrada_em' => null,
        ]);
    }

    public function test_liberar_a_nota_encerra_o_aviso(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);
        $card = $nota->cards()->firstOrFail();

        $this->actingAs($this->preLoteA)->patch(route('notas.cards.resolver', [$nota, $card]));
        $this->actingAs($this->preLoteA)->patch(route('notas.liberar', $nota))->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            Notificacao::where('nota_id', $nota->id)->where('tipo', Notificacao::TIPO_REGRA)->viva()->count(),
        );
    }

    // ─── Não atropela os outros avisos ────────────────────────────────────────

    public function test_regra_e_divergencia_convivem_na_mesma_nota(): void
    {
        /*
         * A mesma armadilha do aviso de doca: se a regra reusasse o tipo
         * 'divergencia', resolver a regra encerraria a cobrança de compras
         * por um card de custo que continua aberto.
         */
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)->post(route('notas.cards.store', $nota), ['tipo' => 'custo']);
        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);

        $regra = $nota->cards()->where('tipo', 'regra')->firstOrFail();
        $this->actingAs($this->preLoteA)->patch(route('notas.cards.resolver', [$nota, $regra]));

        $this->assertDatabaseHas('notificacoes', [
            'user_id'      => $this->compras->id,
            'nota_id'      => $nota->id,
            'tipo'         => Notificacao::TIPO_DIVERGENCIA,
            'encerrada_em' => null,
        ]);
    }

    public function test_corrigir_o_de_compras_nao_apaga_o_de_regra(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLoteA)->post(route('notas.cards.store', $nota), ['tipo' => 'custo']);
        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);

        $custo = $nota->cards()->where('tipo', 'custo')->firstOrFail();
        $this->actingAs($this->compras)->patch(route('notas.cards.corrigir', [$nota, $custo]));

        $this->assertDatabaseHas('notificacoes', [
            'user_id'      => $this->preLoteB->id,
            'nota_id'      => $nota->id,
            'tipo'         => Notificacao::TIPO_REGRA,
            'encerrada_em' => null,
        ]);
    }

    public function test_regra_e_doca_convivem_na_mesma_nota(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'regra']);
        $this->actingAs($this->compras)->post(route('notas.cards.store', $nota), ['tipo' => 'recusa']);

        $regra = $nota->cards()->where('tipo', 'regra')->firstOrFail();
        $this->actingAs($this->preLoteA)->patch(route('notas.cards.resolver', [$nota, $regra]));

        // A recusa segue aberta: o aviso de doca continua vivo para o B
        $this->assertDatabaseHas('notificacoes', [
            'user_id'      => $this->preLoteB->id,
            'nota_id'      => $nota->id,
            'tipo'         => Notificacao::TIPO_DOCA,
            'encerrada_em' => null,
        ]);
        $this->assertSame(
            0,
            Notificacao::where('nota_id', $nota->id)->where('tipo', Notificacao::TIPO_REGRA)->viva()->count(),
        );
    }
}
