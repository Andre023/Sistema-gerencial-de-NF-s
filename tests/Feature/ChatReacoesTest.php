<?php

namespace Tests\Feature;

use App\Events\ReacaoAtualizada;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\MensagemReacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * As reações do chat — o emoji pendurado na mensagem.
 *
 * O que mais importa aqui:
 *   • uma reação por pessoa: trocar de emoji TROCA, não soma
 *   • ninguém reage em conversa alheia
 *   • o campo não vira caixa de texto disfarçada (só os seis da lista passam)
 *   • reagir não acende não lida nem sobe a conversa na lista — é a resposta
 *     que não incomoda, e essa é a razão de ela existir
 */
class ChatReacoesTest extends TestCase
{
    use RefreshDatabase;

    private function pessoa(string $papel = User::ROLE_RECEBIMENTO): User
    {
        return User::factory()->create(['role' => $papel]);
    }

    /** Uma mensagem de $de para $para, já gravada. */
    private function mensagemEntre(User $de, User $para, string $texto = 'chegou a nota'): Mensagem
    {
        $this->actingAs($de)
            ->post(route('conversas.enviar', $para), ['texto' => $texto])
            ->assertCreated();

        return Mensagem::latest('id')->first();
    }

    // ─── Pôr, tirar, trocar ────────────────────────────────────────────────────

    public function test_reagir_pendura_o_emoji_na_mensagem(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa(User::ROLE_COMPRAS);

        $mensagem = $this->mensagemEntre($andre, $maria);

        $this->actingAs($maria)
            ->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => MensagemReacao::PERMITIDOS[0]])
            ->assertOk()
            ->assertJsonPath('reacoes.0.emoji', MensagemReacao::PERMITIDOS[0])
            ->assertJsonPath('reacoes.0.user_id', $maria->id);

        $this->assertDatabaseHas('mensagem_reacoes', [
            'mensagem_id' => $mensagem->id,
            'user_id'     => $maria->id,
            'emoji'       => MensagemReacao::PERMITIDOS[0],
        ]);
    }

    public function test_clicar_no_mesmo_emoji_de_novo_tira_a_reacao(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);
        $polegar  = MensagemReacao::PERMITIDOS[0];

        $this->actingAs($maria)->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => $polegar]);

        $this->actingAs($maria)
            ->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => $polegar])
            ->assertOk()
            ->assertJsonCount(0, 'reacoes');

        $this->assertDatabaseCount('mensagem_reacoes', 0);
    }

    public function test_outro_emoji_troca_a_reacao_em_vez_de_somar(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);

        $polegar = MensagemReacao::PERMITIDOS[0];
        $coracao = MensagemReacao::PERMITIDOS[1];

        $this->actingAs($maria)->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => $polegar]);

        $this->actingAs($maria)
            ->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => $coracao])
            ->assertOk()
            ->assertJsonCount(1, 'reacoes')
            ->assertJsonPath('reacoes.0.emoji', $coracao);

        // Uma pessoa, uma linha — o unique do banco é o que garante
        $this->assertDatabaseCount('mensagem_reacoes', 1);
    }

    public function test_duas_pessoas_com_o_mesmo_emoji_contam_duas(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);
        $polegar  = MensagemReacao::PERMITIDOS[0];

        $this->actingAs($maria)->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => $polegar]);
        $this->actingAs($andre)->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => $polegar]);

        $this->assertDatabaseCount('mensagem_reacoes', 2);
    }

    // ─── O que não pode ────────────────────────────────────────────────────────

    public function test_emoji_fora_da_lista_nao_entra(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);

        // Sem a lista fechada, este campo seria uma segunda caixa de texto —
        // 32 caracteres livres vindos de uma requisição montada à mão.
        $this->actingAs($maria)
            ->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => 'kkkk'])
            ->assertSessionHasErrors('emoji');

        $this->assertDatabaseCount('mensagem_reacoes', 0);
    }

    public function test_terceiro_nao_reage_em_conversa_alheia(): void
    {
        $andre    = $this->pessoa();
        $maria    = $this->pessoa();
        $estranho = $this->pessoa(User::ROLE_COMPRAS);

        $mensagem = $this->mensagemEntre($andre, $maria);

        $this->actingAs($estranho)
            ->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => MensagemReacao::PERMITIDOS[0]])
            ->assertNotFound();

        $this->assertDatabaseCount('mensagem_reacoes', 0);
    }

    // ─── Como a reação chega na tela ───────────────────────────────────────────

    public function test_a_reacao_volta_junto_com_a_conversa(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);
        $reza     = MensagemReacao::PERMITIDOS[5];

        $this->actingAs($maria)->post(route('conversas.mensagens.reagir', $mensagem), ['emoji' => $reza]);

        $this->actingAs($andre)
            ->get(route('conversas.mostrar', $maria))
            ->assertOk()
            ->assertJsonPath('mensagens.0.reacoes.0.emoji', $reza)
            ->assertJsonPath('mensagens.0.reacoes.0.user_id', $maria->id);
    }

    public function test_mensagem_sem_reacao_traz_lista_vazia_e_nao_nulo(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->mensagemEntre($andre, $maria);

        // A tela faz `.map()` em cima disto. Nulo aqui viraria tela branca.
        $this->actingAs($andre)
            ->get(route('conversas.mostrar', $maria))
            ->assertOk()
            ->assertJsonCount(0, 'mensagens.0.reacoes');
    }

    public function test_os_dois_lados_sao_avisados(): void
    {
        Event::fake([ReacaoAtualizada::class]);

        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);

        $this->actingAs($maria)->post(route('conversas.mensagens.reagir', $mensagem), [
            'emoji' => MensagemReacao::PERMITIDOS[2],
        ]);

        Event::assertDispatched(ReacaoAtualizada::class, function (ReacaoAtualizada $e) use ($andre, $maria, $mensagem) {
            return $e->mensagemId === $mensagem->id
                && in_array($andre->id, $e->destinatarios, true)
                && in_array($maria->id, $e->destinatarios, true);
        });
    }

    // ─── O que reagir NÃO faz ──────────────────────────────────────────────────

    public function test_reagir_nao_acende_nao_lida_nem_sobe_a_conversa(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);

        // André já leu tudo (mandou ele mesmo). O relógio da conversa fica aqui.
        $conversa = Conversa::first();
        $antes    = $conversa->ultima_mensagem_em;

        $this->actingAs($maria)->post(route('conversas.mensagens.reagir', $mensagem), [
            'emoji' => MensagemReacao::PERMITIDOS[0],
        ]);

        /*
         * A razão de a reação existir é ser a resposta que NÃO incomoda: não
         * pode fazer o celular do outro apitar nem empurrar a conversa para o
         * topo da lista dele. Se um dia isso mudar, este teste cai — e tem de
         * cair, porque descaracteriza o recurso.
         */
        $this->assertSame(0, $conversa->fresh()->naoLidasPara($andre));
        $this->assertEquals($antes, $conversa->fresh()->ultima_mensagem_em);
    }

    public function test_apagar_a_mensagem_leva_as_reacoes_junto(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $mensagem = $this->mensagemEntre($andre, $maria);

        $this->actingAs($maria)->post(route('conversas.mensagens.reagir', $mensagem), [
            'emoji' => MensagemReacao::PERMITIDOS[0],
        ]);

        $this->assertDatabaseCount('mensagem_reacoes', 1);

        // É o cascade do banco que faz isto — a faxina de 21 dias apaga a
        // mensagem e nem precisa saber que reações existem.
        $mensagem->delete();

        $this->assertDatabaseCount('mensagem_reacoes', 0);
    }

    public function test_a_lista_de_emojis_tem_os_seis_da_barra(): void
    {
        /*
         * A mesma lista existe em dois lugares: aqui no servidor e em
         * BarraReacoes.tsx. Divergindo, a tela ofereceria um emoji que o
         * servidor recusa — e o clique falharia calado.
         *
         * Este teste não lê o arquivo .tsx (seria frágil); ele fixa a
         * quantidade e a ordem, de modo que mexer de um lado só faça o teste
         * falhar e lembrar do outro.
         */
        $this->assertCount(6, MensagemReacao::PERMITIDOS);

        $this->assertSame(
            '1f44d',
            dechex(mb_ord(MensagemReacao::PERMITIDOS[0])),
            'O primeiro tem de ser o joinha — é o padrão da barra e o mais usado.',
        );
    }
}
