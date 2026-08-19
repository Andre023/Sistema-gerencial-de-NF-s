<?php

namespace Tests\Feature;

use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\MensagemReacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A janela de três semanas do chat.
 *
 * ── A regra, em uma frase ─────────────────────────────────────────────────
 * Cada MENSAGEM morre 21 dias depois DELA MESMA. Não existe zeragem coletiva.
 *
 * A confusão possível — e é justamente o que os testes daqui existem para
 * impedir — seria "de três em três semanas, limpa tudo". Isso levaria junto a
 * mensagem de dez minutos atrás só porque o calendário virou.
 *
 * O certo é uma janela DESLIZANTE: a conversa vai perdendo o rabo enquanto
 * ganha começo. Quem conversa todo dia tem sempre as últimas três semanas
 * inteiras na tela, e nunca amanhece com o chat vazio.
 *
 * O teste que guarda essa distinção é
 * test_a_faxina_nao_zera_a_conversa_so_apara_o_rabo.
 */
class ChatJanelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Mensagem::DISCO);
    }

    private function pessoa(): User
    {
        return User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
    }

    /**
     * Uma mensagem gravada com a idade que se pedir.
     *
     * `forceFill` porque `created_at` não é preenchível — e é exatamente ele
     * que a faxina lê para decidir.
     */
    private function mensagemCom(Conversa $conversa, User $autor, string $texto, int $diasAtras): Mensagem
    {
        $mensagem = $conversa->mensagens()->create([
            'user_id' => $autor->id,
            'texto'   => $texto,
        ]);

        $mensagem->forceFill(['created_at' => now()->subDays($diasAtras)])->save();

        return $mensagem;
    }

    // ─── O coração da regra ────────────────────────────────────────────────────

    public function test_a_faxina_nao_zera_a_conversa_so_apara_o_rabo(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);

        // Uma conversa viva: gente falando ao longo de um mês
        $muitoVelha = $this->mensagemCom($conversa, $andre, 'combinado de um mes atras', 30);
        $noLimite   = $this->mensagemCom($conversa, $maria, 'ainda dentro do prazo', 20);
        $ontem      = $this->mensagemCom($conversa, $andre, 'nota 4471 chegou', 1);
        $agora      = $this->mensagemCom($conversa, $maria, 'ja confiro', 0);

        $this->artisan('chat:limpar-mensagens')->assertSuccessful();

        // Só a que passou dos 21 dias saiu...
        $this->assertDatabaseMissing('mensagens', ['id' => $muitoVelha->id]);

        // ...e TUDO o que ainda não fez 21 dias continua onde estava.
        $this->assertDatabaseHas('mensagens', ['id' => $noLimite->id]);
        $this->assertDatabaseHas('mensagens', ['id' => $ontem->id]);
        $this->assertDatabaseHas('mensagens', ['id' => $agora->id]);

        $this->assertSame(3, $conversa->fresh()->mensagens()->count(),
            'A faxina não pode esvaziar a conversa — ela apara o rabo, não zera.');
    }

    public function test_cada_mensagem_conta_o_proprio_prazo(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);

        // Um dia de cada lado da fronteira dos 21
        $passou = $this->mensagemCom($conversa, $andre, 'passou um dia do prazo', Mensagem::DIAS_DE_VIDA + 1);
        $falta  = $this->mensagemCom($conversa, $andre, 'falta um dia para o prazo', Mensagem::DIAS_DE_VIDA - 1);

        $this->artisan('chat:limpar-mensagens')->assertSuccessful();

        $this->assertDatabaseMissing('mensagens', ['id' => $passou->id]);
        $this->assertDatabaseHas('mensagens', ['id' => $falta->id]);
    }

    public function test_a_faxina_de_amanha_leva_o_que_hoje_ficou(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);

        $mensagem = $this->mensagemCom($conversa, $andre, 'no fio do prazo', Mensagem::DIAS_DE_VIDA - 1);

        // Hoje ela fica
        $this->artisan('chat:limpar-mensagens')->assertSuccessful();
        $this->assertDatabaseHas('mensagens', ['id' => $mensagem->id]);

        /*
         * Amanhã ela sai. É isto que faz a janela DESLIZAR: a mesma mensagem
         * muda de lado sozinha com a passagem do tempo, sem ninguém mexer em
         * nada. Não há "dia da limpeza" — há a idade de cada linha.
         */
        $this->travel(2)->days();
        $this->artisan('chat:limpar-mensagens')->assertSuccessful();
        $this->assertDatabaseMissing('mensagens', ['id' => $mensagem->id]);

        $this->travelBack();
    }

    public function test_conversa_parada_ha_meses_esvazia_mas_continua_existindo(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);
        $this->mensagemCom($conversa, $andre, 'assunto encerrado', 60);

        $this->artisan('chat:limpar-mensagens')->assertSuccessful();

        $this->assertSame(0, $conversa->fresh()->mensagens()->count());

        /*
         * A LINHA da conversa fica de propósito: é ela que guarda até onde cada
         * um leu (conversa_participantes.lida_ate_id). Apagada, os dois lados
         * perderiam o ponteiro e a próxima mensagem poderia reacender contador
         * de coisa que já tinha sido lida.
         *
         * Na tela isso não aparece: a barra lista TODO MUNDO, com conversa ou
         * sem. Uma conversa vazia se comporta igual a uma que nunca aconteceu.
         */
        $this->assertDatabaseHas('conversas', ['id' => $conversa->id]);
        $this->assertNull($conversa->fresh()->ultima_mensagem_em);
    }

    public function test_o_relogio_da_conversa_e_refeito_depois_da_faxina(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);

        $this->mensagemCom($conversa, $andre, 'velha', 30);
        $recente = $this->mensagemCom($conversa, $maria, 'recente', 2);

        $conversa->update(['ultima_mensagem_em' => now()->subDays(2)]);

        $this->artisan('chat:limpar-mensagens')->assertSuccessful();

        // Sem o conserto, a coluna apontaria para uma mensagem que não existe
        $this->assertSame(
            $recente->created_at->format('Y-m-d H:i:s'),
            $conversa->fresh()->ultima_mensagem_em->format('Y-m-d H:i:s'),
        );
    }

    // ─── O que vai junto ───────────────────────────────────────────────────────

    public function test_a_reacao_sai_com_a_mensagem(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);
        $velha    = $this->mensagemCom($conversa, $andre, 'combinado antigo', 30);

        MensagemReacao::create([
            'mensagem_id' => $velha->id,
            'user_id'     => $maria->id,
            'emoji'       => MensagemReacao::PERMITIDOS[0],
        ]);

        $this->artisan('chat:limpar-mensagens')->assertSuccessful();

        // Pelo cascade do banco — a faxina nem sabe que reações existem
        $this->assertDatabaseCount('mensagem_reacoes', 0);
    }

    public function test_anexo_que_sobrou_no_disco_vai_junto(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('nota.jpg'),
        ])->assertCreated();

        $mensagem = Mensagem::first();
        $caminho  = $mensagem->anexo_caminho;

        /*
         * Normalmente o arquivo já teria saído aos 3 dias, pelo chat:limpar-anexos.
         * Este caso é o do arquivo que sobreviveu: faxina que não rodou por uns
         * dias, backup restaurado, `--dias` na mão. A faxina das mensagens não
         * pode deixar arquivo órfão no disco, sem registro que aponte para ele.
         */
        $mensagem->forceFill(['created_at' => now()->subDays(Mensagem::DIAS_DE_VIDA + 1)])->save();

        $this->artisan('chat:limpar-mensagens')->assertSuccessful();

        Storage::disk(Mensagem::DISCO)->assertMissing($caminho);
        $this->assertDatabaseMissing('mensagens', ['id' => $mensagem->id]);
    }

    // ─── A ferramenta ──────────────────────────────────────────────────────────

    public function test_simular_nao_apaga_nada(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);
        $velha    = $this->mensagemCom($conversa, $andre, 'velha', 30);

        $this->artisan('chat:limpar-mensagens', ['--simular' => true])->assertSuccessful();

        $this->assertDatabaseHas('mensagens', ['id' => $velha->id]);
    }

    public function test_dias_zero_forca_a_limpeza(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);
        $this->mensagemCom($conversa, $andre, 'de agora ha pouco', 0);

        /*
         * Em PHP o "0" é falso, e um `?:` mandaria isto direto para o prazo
         * padrão — a faxina diria "nada a limpar" com a mensagem ali, parada.
         * Mesma armadilha do chat:limpar-anexos, mesmo teste.
         */
        $this->artisan('chat:limpar-mensagens', ['--dias' => 0])->assertSuccessful();

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_nada_a_limpar_nao_e_erro(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $conversa = Conversa::entre($andre, $maria);
        $this->mensagemCom($conversa, $andre, 'recente', 1);

        // A faxina roda toda madrugada. Na maioria delas não há o que fazer, e
        // isso não pode virar erro no log nem sair diferente de zero.
        $this->artisan('chat:limpar-mensagens')
            ->expectsOutputToContain('Nada a limpar')
            ->assertSuccessful();

        $this->assertDatabaseCount('mensagens', 1);
    }
}
