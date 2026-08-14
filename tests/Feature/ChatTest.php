<?php

namespace Tests\Feature;

use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O chat interno.
 *
 * O que mais importa aqui não é o caminho feliz (mandar e receber). É:
 *   • duas pessoas nunca acabarem em conversas diferentes uma da outra
 *   • ninguém ler conversa alheia, nem baixar arquivo de conversa alheia
 *   • o arquivo sair do disco no prazo, e a mensagem sobreviver a ele
 */
class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Mensagem::DISCO);
    }

    private function pessoa(string $papel = User::ROLE_RECEBIMENTO): User
    {
        return User::factory()->create(['role' => $papel]);
    }

    // ─── A conversa é uma só ───────────────────────────────────────────────────

    public function test_os_dois_lados_caem_na_mesma_conversa(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa(User::ROLE_COMPRAS);

        $a = Conversa::entre($andre, $maria);
        $b = Conversa::entre($maria, $andre);

        $this->assertSame($a->id, $b->id, 'A ordem de quem abriu não pode criar duas conversas.');
        $this->assertDatabaseCount('conversas', 1);
    }

    public function test_mandar_mensagem_nao_cria_conversa_nova_a_cada_vez(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa(User::ROLE_COMPRAS);

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'primeira'])
            ->assertCreated();
        $this->actingAs($maria)->post(route('conversas.enviar', $andre), ['texto' => 'segunda'])
            ->assertCreated();

        $this->assertDatabaseCount('conversas', 1);
        $this->assertDatabaseCount('mensagens', 2);
    }

    public function test_so_espiar_a_conversa_nao_deixa_lixo_no_banco(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->get(route('conversas.mostrar', $maria))
            ->assertOk()
            ->assertJson(['conversa_id' => null, 'mensagens' => []]);

        $this->assertDatabaseCount('conversas', 0);
    }

    public function test_ninguem_conversa_consigo_mesmo(): void
    {
        $andre = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $andre), ['texto' => 'oi'])
            ->assertStatus(422);
    }

    // ─── Quem pode o quê ───────────────────────────────────────────────────────

    public function test_visitante_conversa_normalmente(): void
    {
        // O visitante é só-leitura na FILA DE NOTAS. Conversar não age sobre
        // nota nenhuma — travar isso só deixaria um colega incomunicável.
        $visitante = $this->pessoa(User::ROLE_VISITANTE);
        $maria     = $this->pessoa(User::ROLE_COMPRAS);

        $this->actingAs($visitante)->post(route('conversas.enviar', $maria), ['texto' => 'bom dia'])
            ->assertCreated();
    }

    public function test_quem_nao_esta_logado_nao_ve_nada(): void
    {
        $andre = $this->pessoa();

        $this->get(route('conversas.index'))->assertRedirect(route('login'));
        $this->get(route('conversas.mostrar', $andre))->assertRedirect(route('login'));
    }

    public function test_terceiro_nao_le_conversa_dos_outros(): void
    {
        $andre  = $this->pessoa();
        $maria  = $this->pessoa();
        $intruso = $this->pessoa(User::ROLE_ADMIN);

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'segredo']);

        $conversa = Conversa::first();

        // Nem admin: a conversa é dos dois, e ninguém mais
        $this->actingAs($intruso)->post(route('conversas.lida', $conversa))->assertForbidden();
    }

    public function test_terceiro_nao_baixa_anexo_dos_outros(): void
    {
        $andre   = $this->pessoa();
        $maria   = $this->pessoa();
        $intruso = $this->pessoa(User::ROLE_ADMIN);

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('avaria.jpg'),
        ])->assertCreated();

        $mensagem = Mensagem::first();

        $this->actingAs($intruso)->get(route('conversas.mensagens.arquivo', $mensagem))->assertNotFound();
        $this->actingAs($maria)->get(route('conversas.mensagens.arquivo', $mensagem))->assertOk();
    }

    // ─── Conteúdo ──────────────────────────────────────────────────────────────

    public function test_mensagem_vazia_nao_passa(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => '   '])
            ->assertSessionHasErrors('texto');

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_arquivo_pode_ir_sozinho_sem_texto(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('foto.webp'),
        ])->assertCreated();

        $mensagem = Mensagem::first();

        $this->assertNull($mensagem->texto);
        $this->assertTrue($mensagem->temAnexo());
        Storage::disk(Mensagem::DISCO)->assertExists($mensagem->anexo_caminho);
    }

    public function test_formato_perigoso_nao_entra(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        // SVG é XML e aceita <script> dentro — servido na mesma origem, rodaria
        // com a sessão de quem abrisse.
        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->create('golpe.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('arquivo');

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_o_nome_do_arquivo_nao_vira_caminho(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('../../../etc/passwd.jpg'),
        ])->assertCreated();

        $mensagem = Mensagem::first();

        $this->assertStringStartsWith("chat/{$mensagem->conversa_id}/", $mensagem->anexo_caminho);
        $this->assertStringNotContainsString('..', $mensagem->anexo_caminho);
    }

    // ─── Não lidas ─────────────────────────────────────────────────────────────

    public function test_o_contador_conta_so_o_que_o_outro_mandou(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'uma']);
        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'duas']);

        $conversa = Conversa::first();

        $this->assertSame(2, $conversa->naoLidasPara($maria->fresh()));
        // Quem mandou não tem nada por ler do que ele mesmo escreveu
        $this->assertSame(0, $conversa->naoLidasPara($andre->fresh()));
    }

    public function test_abrir_a_conversa_zera_o_contador(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'oi']);

        $this->actingAs($maria)->get(route('conversas.mostrar', $andre))->assertOk();

        $this->assertSame(0, Conversa::first()->naoLidasPara($maria->fresh()));
    }

    public function test_abrir_uma_conversa_nao_marca_as_outras_como_lidas(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();
        $ana   = $this->pessoa();

        $this->actingAs($maria)->post(route('conversas.enviar', $andre), ['texto' => 'da maria']);
        $this->actingAs($ana)->post(route('conversas.enviar', $andre), ['texto' => 'da ana']);

        // André abre SÓ a da Maria
        $this->actingAs($andre)->get(route('conversas.mostrar', $maria))->assertOk();

        $resposta = $this->actingAs($andre)->get(route('conversas.index'))->assertOk();

        $this->assertSame(1, $resposta->json('nao_lidas'), 'A mensagem da Ana continua por ler.');

        $linhaAna = collect($resposta->json('pessoas'))->firstWhere('id', $ana->id);
        $this->assertSame(1, $linhaAna['nao_lidas']);
    }

    public function test_abrir_diz_onde_a_leitura_tinha_parado(): void
    {
        // É o que faz a conversa abrir na primeira não lida, e não no começo do
        // histórico. Tem de ser o valor de ANTES de esta abertura marcar tudo
        // como lido — depois dela a informação deixa de existir.
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($maria)->post(route('conversas.enviar', $andre), ['texto' => 'uma']);
        $primeira = Mensagem::max('id');

        // André lê até aqui
        $this->actingAs($andre)->get(route('conversas.mostrar', $maria))->assertOk();

        // Chegam mais duas
        $this->actingAs($maria)->post(route('conversas.enviar', $andre), ['texto' => 'duas']);
        $this->actingAs($maria)->post(route('conversas.enviar', $andre), ['texto' => 'tres']);

        $resposta = $this->actingAs($andre)->get(route('conversas.mostrar', $maria))->assertOk();

        // A marca d'água é a primeira mensagem — as duas seguintes são as novas
        $this->assertSame($primeira, $resposta->json('minha_leitura_ate'));

        // E ao reabrir, já não há nada por ler
        $this->actingAs($andre)->get(route('conversas.mostrar', $maria))
            ->assertJson(['minha_leitura_ate' => Mensagem::max('id')]);
    }

    public function test_conversa_toda_lida_nao_aponta_para_lugar_nenhum(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'so minha']);

        $resposta = $this->actingAs($andre)->get(route('conversas.mostrar', $maria))->assertOk();

        // Quem mandou já leu: a marca é a própria mensagem, e a tela abre no fim
        $this->assertSame(Mensagem::max('id'), $resposta->json('minha_leitura_ate'));
    }

    public function test_abrir_ainda_avisa_o_outro_com_o_valor_novo(): void
    {
        /*
         * Guarda contra uma armadilha real: para saber onde a leitura tinha
         * parado, o controller lê o ponteiro ANTES de marcar como lido. Se essa
         * leitura carregasse a relação como propriedade, o aviso de ✓✓ enviado
         * logo depois sairia com o valor VELHO — e o outro lado nunca veria a
         * mensagem como lida.
         */
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($maria)->post(route('conversas.enviar', $andre), ['texto' => 'leia isso']);
        $ultima = Mensagem::max('id');

        $this->actingAs($andre)->get(route('conversas.mostrar', $maria))->assertOk();

        // Do ponto de vista da Maria, o André já leu até a última
        $resposta = $this->actingAs($maria)->get(route('conversas.mostrar', $andre))->assertOk();

        $this->assertSame($ultima, $resposta->json('lida_pelo_outro_ate'));
    }

    public function test_marcar_lida_nunca_anda_para_tras(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'uma']);
        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'duas']);

        $conversa = Conversa::first();
        $ultima   = Mensagem::max('id');

        $this->actingAs($maria)->post(route('conversas.lida', $conversa), ['ate' => $ultima]);
        // Requisição atrasada chegando fora de ordem não pode "desler"
        $this->actingAs($maria)->post(route('conversas.lida', $conversa), ['ate' => 1]);

        $this->assertSame(0, $conversa->fresh()->naoLidasPara($maria->fresh()));
    }

    // ─── A vida do arquivo ─────────────────────────────────────────────────────

    public function test_o_anexo_sai_do_disco_depois_do_prazo(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('nota.jpg'),
        ]);

        $mensagem = Mensagem::first();
        $caminho  = $mensagem->anexo_caminho;

        // Ainda dentro do prazo: a faxina não encosta
        $this->artisan('chat:limpar-anexos')->assertSuccessful();
        Storage::disk(Mensagem::DISCO)->assertExists($caminho);

        // Envelhece a mensagem para além do prazo
        $mensagem->forceFill([
            'created_at' => now()->subDays(Mensagem::DIAS_NO_SERVIDOR + 1),
        ])->save();

        $this->artisan('chat:limpar-anexos')->assertSuccessful();

        Storage::disk(Mensagem::DISCO)->assertMissing($caminho);

        // A MENSAGEM continua: a conversa não pode ficar com buraco
        $this->assertDatabaseHas('mensagens', ['id' => $mensagem->id]);
        $this->assertNotNull($mensagem->fresh()->anexo_removido_em);
        $this->assertFalse($mensagem->fresh()->anexoNoServidor());
    }

    public function test_dias_zero_forca_a_limpeza(): void
    {
        // `--dias=0` é o jeito de forçar a faxina na mão. Em PHP o "0" da linha
        // de comando é FALSO: escrito com `?:`, o comando cairia no prazo padrão
        // e responderia "nada a limpar" com os arquivos parados no disco.
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('agora.jpg'),
        ]);

        $mensagem = Mensagem::first();

        $this->artisan('chat:limpar-anexos', ['--dias' => 0])->assertSuccessful();

        Storage::disk(Mensagem::DISCO)->assertMissing($mensagem->anexo_caminho);
        $this->assertNotNull($mensagem->fresh()->anexo_removido_em);
    }

    public function test_baixar_anexo_ja_removido_responde_410(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('nota.jpg'),
        ]);

        $mensagem = Mensagem::first();
        $mensagem->soltarArquivo();

        // 410 e não 404: sumiu por decisão, não por erro — a tela usa isso para
        // explicar em vez de dizer "não encontrado".
        $this->actingAs($maria)->get(route('conversas.mensagens.arquivo', $mensagem))
            ->assertStatus(410);
    }

    public function test_soltar_o_arquivo_duas_vezes_nao_quebra(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('nota.jpg'),
        ]);

        $mensagem = Mensagem::first();

        $mensagem->soltarArquivo();
        $primeiro = $mensagem->fresh()->anexo_removido_em;

        $mensagem->fresh()->soltarArquivo();

        $this->assertEquals($primeiro, $mensagem->fresh()->anexo_removido_em);
    }

    // ─── A lista da barra ──────────────────────────────────────────────────────

    public function test_a_lista_traz_todo_mundo_menos_eu(): void
    {
        $andre = $this->pessoa();
        $this->pessoa();
        $this->pessoa();

        $resposta = $this->actingAs($andre)->get(route('conversas.index'))->assertOk();

        $pessoas = $resposta->json('pessoas');

        $this->assertCount(2, $pessoas);
        $this->assertNotContains($andre->id, array_column($pessoas, 'id'));
    }

    public function test_a_lista_mostra_a_previa_e_o_nao_lido(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'a nota 123 chegou']);

        $resposta = $this->actingAs($maria)->get(route('conversas.index'))->assertOk();

        $linha = collect($resposta->json('pessoas'))->firstWhere('id', $andre->id);

        $this->assertSame('a nota 123 chegou', $linha['ultima']['previa']);
        $this->assertFalse($linha['ultima']['minha']);
        $this->assertSame(1, $linha['nao_lidas']);
        $this->assertSame(1, $resposta->json('nao_lidas'));
    }

    public function test_a_previa_de_arquivo_sem_legenda_vira_rotulo(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), [
            'arquivo' => UploadedFile::fake()->image('avaria.jpg'),
        ]);

        $resposta = $this->actingAs($maria)->get(route('conversas.index'));
        $linha    = collect($resposta->json('pessoas'))->firstWhere('id', $andre->id);

        $this->assertSame('Foto', $linha['ultima']['previa']);
    }

    public function test_mensagem_de_conta_removida_continua_contando(): void
    {
        $andre = $this->pessoa();
        $maria = $this->pessoa();

        $this->actingAs($andre)->post(route('conversas.enviar', $maria), ['texto' => 'último recado']);

        // A conta sai; a mensagem fica com autor nulo (nullOnDelete)
        $andre->delete();

        $resposta = $this->actingAs($maria)->get(route('conversas.index'))->assertOk();

        // Em SQL, `NULL != x` não é verdadeiro — sem o tratamento no Conversas,
        // esta mensagem sumiria silenciosamente da contagem.
        $this->assertSame(1, $resposta->json('nao_lidas'));
    }
}
