<?php

namespace Tests\Feature;

use App\Models\Devolucao;
use App\Models\DevolucaoAnexo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O quadro de devoluções entre pré-lote e recebimento.
 *
 * Substitui um recado de WhatsApp que sempre teve a mesma forma: print, nota,
 * fornecedor, motivo, quem autorizou e quando o boleto vence.
 *
 * O que mais importa aqui não é o caminho feliz:
 *   • sem print não entra — era o vício do grupo, o bilhete sem prova
 *   • os DOIS setores abrem e conferem; compras e visitante ficam de fora
 *   • o arquivo some uma semana DEPOIS DE CONFERIDO, nunca antes
 */
class DevolucaoTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $preLote;
    private User $compras;
    private User $visitante;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DevolucaoAnexo::DISCO);

        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->visitante   = User::factory()->create(['role' => User::ROLE_VISITANTE]);
    }

    /** O recado do exemplo, virado em requisição. */
    private function dados(array $extra = []): array
    {
        return [
            'numero_nota'    => '310712',
            'fornecedor'     => 'VERDE CAMPO',
            'motivo'         => 'FALTA',
            'autorizado_por' => 'FELIPE CABRAL',
            'boleto_vence'   => '2026-09-11',
            'arquivos'       => [UploadedFile::fake()->image('print.png', 900, 600)],
            ...$extra,
        ];
    }

    private function lanca(User $user, array $extra = [])
    {
        return $this->actingAs($user)->post(route('devolucoes.store'), $this->dados($extra));
    }

    // ─── Lançar ───────────────────────────────────────────────────────────────

    public function test_lanca_com_todos_os_campos_do_recado(): void
    {
        $this->lanca($this->recebimento)->assertCreated();

        $this->assertDatabaseHas('devolucoes', [
            'numero_nota'    => '310712',
            'fornecedor'     => 'VERDE CAMPO',
            'motivo'         => 'FALTA',
            'autorizado_por' => 'FELIPE CABRAL',
            'conferida_em'   => null,
        ]);

        $devolucao = Devolucao::firstOrFail();

        $this->assertSame('2026-09-11', $devolucao->boleto_vence->toDateString());
        $this->assertSame($this->recebimento->id, $devolucao->criada_por);
        $this->assertCount(1, $devolucao->anexos);

        Storage::disk(DevolucaoAnexo::DISCO)->assertExists($devolucao->anexos->first()->caminho);
    }

    /** O ponto do exercício: no WhatsApp dava para mandar o recado sem o print. */
    public function test_sem_print_nao_entra(): void
    {
        $this->actingAs($this->preLote)
            ->post(route('devolucoes.store'), array_diff_key($this->dados(), ['arquivos' => null]))
            ->assertSessionHasErrors('arquivos');

        $this->assertDatabaseCount('devolucoes', 0);
    }

    /** @return array<array<string>> */
    public static function camposObrigatorios(): array
    {
        return [['numero_nota'], ['fornecedor'], ['motivo'], ['autorizado_por']];
    }

    #[DataProvider('camposObrigatorios')]
    public function test_cada_campo_do_recado_e_obrigatorio(string $campo): void
    {
        $this->actingAs($this->preLote)
            ->post(route('devolucoes.store'), $this->dados([$campo => '']))
            ->assertSessionHasErrors($campo);

        $this->assertDatabaseCount('devolucoes', 0);
    }

    /** O boleto pode não ter data — nem toda devolução sai com boleto emitido. */
    public function test_boleto_sem_data_passa(): void
    {
        $this->lanca($this->recebimento, ['boleto_vence' => ''])->assertCreated();

        $this->assertNull(Devolucao::firstOrFail()->boleto_vence);
    }

    public function test_aceita_varios_prints_e_pdf(): void
    {
        $this->lanca($this->preLote, ['arquivos' => [
            UploadedFile::fake()->image('print1.png'),
            UploadedFile::fake()->image('print2.jpg'),
            UploadedFile::fake()->create('boleto.pdf', 40, 'application/pdf'),
        ]])->assertCreated();

        $this->assertCount(3, Devolucao::firstOrFail()->anexos);
    }

    public function test_formato_perigoso_nao_entra(): void
    {
        // SVG é XML e aceita <script> dentro — servido na mesma origem, rodaria
        // com a sessão de quem abrisse.
        $this->actingAs($this->preLote)
            ->post(route('devolucoes.store'), $this->dados([
                'arquivos' => [UploadedFile::fake()->create('golpe.svg', 10, 'image/svg+xml')],
            ]))
            ->assertSessionHasErrors('arquivos.0');

        $this->assertDatabaseCount('devolucoes', 0);
    }

    public function test_o_nome_do_arquivo_nao_vira_caminho(): void
    {
        $this->lanca($this->preLote, [
            'arquivos' => [UploadedFile::fake()->image('../../../etc/passwd.jpg')],
        ])->assertCreated();

        $anexo = DevolucaoAnexo::firstOrFail();

        $this->assertStringStartsWith("devolucoes/{$anexo->devolucao_id}/", $anexo->caminho);
        $this->assertStringNotContainsString('..', $anexo->caminho);
    }

    // ─── Quem pode ────────────────────────────────────────────────────────────

    public function test_os_dois_setores_lancam(): void
    {
        // Não há dono do card: o aviso vai numa direção ou na outra conforme
        // quem descobriu o problema.
        foreach ([$this->recebimento, $this->preLote] as $user) {
            $this->lanca($user)->assertCreated();
        }

        $this->assertDatabaseCount('devolucoes', 2);
    }

    public function test_os_dois_setores_conferem(): void
    {
        $this->lanca($this->recebimento);
        $devolucao = Devolucao::firstOrFail();

        // Quem lançou foi o recebimento; quem confere é o outro lado
        $this->actingAs($this->preLote)
            ->post(route('devolucoes.conferir', $devolucao))
            ->assertOk();

        $devolucao->refresh();

        $this->assertNotNull($devolucao->conferida_em);
        $this->assertSame($this->preLote->id, $devolucao->conferida_por);
    }

    public function test_compras_e_visitante_ficam_de_fora(): void
    {
        foreach ([$this->compras, $this->visitante] as $user) {
            $this->actingAs($user)
                ->post(route('devolucoes.store'), $this->dados())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('devolucoes', 0);
    }

    public function test_quem_nao_esta_logado_nao_ve_o_arquivo(): void
    {
        /*
         * O card é montado direto pelo model, sem passar pelo controller.
         *
         * Não é preciosismo: `actingAs` vale para TODAS as requisições
         * seguintes do mesmo teste. Lançando por HTTP, o pedido "de visitante
         * anônimo" logo abaixo sairia com a sessão do pré-lote ainda montada —
         * e o teste passaria mesmo se a rota estivesse aberta.
         */
        $devolucao = Devolucao::create([
            'numero_nota' => '310712', 'fornecedor' => 'VERDE CAMPO',
            'motivo' => 'FALTA', 'autorizado_por' => 'FELIPE CABRAL',
        ]);

        $anexo = $devolucao->anexos()->create([
            'caminho' => 'devolucoes/1/print.png', 'nome_original' => 'print.png',
            'mime' => 'image/png', 'tamanho' => 100,
        ]);

        $this->get(route('devolucoes.arquivo', [$devolucao, $anexo]))
            ->assertRedirect(route('login'));
    }

    public function test_compras_nao_baixa_o_arquivo(): void
    {
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();
        $anexo = $devolucao->anexos->first();

        $this->actingAs($this->compras)
            ->get(route('devolucoes.arquivo', [$devolucao, $anexo]))
            ->assertForbidden();

        $this->actingAs($this->recebimento)
            ->get(route('devolucoes.arquivo', [$devolucao, $anexo]))
            ->assertOk();
    }

    // ─── Conferir ─────────────────────────────────────────────────────────────

    public function test_conferir_duas_vezes_e_recusado(): void
    {
        // Senão a semana até a faxina recomeçaria a cada clique.
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();

        $this->actingAs($this->recebimento)->post(route('devolucoes.conferir', $devolucao))->assertOk();

        $primeira = $devolucao->fresh()->conferida_em;

        $this->actingAs($this->preLote)
            ->post(route('devolucoes.conferir', $devolucao))
            ->assertStatus(422);

        $this->assertEquals($primeira, $devolucao->fresh()->conferida_em);
    }

    public function test_reabrir_devolve_o_card_ao_quadro(): void
    {
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();

        $this->actingAs($this->recebimento)->post(route('devolucoes.conferir', $devolucao));
        $this->actingAs($this->recebimento)->post(route('devolucoes.reabrir', $devolucao))->assertOk();

        $this->assertNull($devolucao->fresh()->conferida_em);
        $this->assertNull($devolucao->fresh()->conferida_por);
    }

    // ─── O card não fica sem prova ────────────────────────────────────────────

    public function test_nao_da_para_remover_o_ultimo_arquivo(): void
    {
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();
        $anexo = $devolucao->anexos->first();

        $this->actingAs($this->preLote)
            ->delete(route('devolucoes.anexos.destroy', [$devolucao, $anexo]))
            ->assertStatus(422);

        $this->assertCount(1, $devolucao->fresh()->anexos);
    }

    public function test_da_para_remover_quando_ha_mais_de_um(): void
    {
        $this->lanca($this->preLote, ['arquivos' => [
            UploadedFile::fake()->image('a.png'),
            UploadedFile::fake()->image('b.png'),
        ]]);

        $devolucao = Devolucao::firstOrFail();
        $anexo = $devolucao->anexos->first();

        $this->actingAs($this->preLote)
            ->delete(route('devolucoes.anexos.destroy', [$devolucao, $anexo]))
            ->assertOk();

        $this->assertCount(1, $devolucao->fresh()->anexos);
        Storage::disk(DevolucaoAnexo::DISCO)->assertMissing($anexo->caminho);
    }

    public function test_excluir_o_card_leva_os_arquivos(): void
    {
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();
        $caminho = $devolucao->anexos->first()->caminho;

        $this->actingAs($this->preLote)
            ->delete(route('devolucoes.destroy', $devolucao))
            ->assertOk();

        $this->assertDatabaseCount('devolucoes', 0);
        $this->assertDatabaseCount('devolucao_anexos', 0);
        Storage::disk(DevolucaoAnexo::DISCO)->assertMissing($caminho);
    }

    // ─── A faxina ─────────────────────────────────────────────────────────────

    public function test_arquivo_de_card_nao_conferido_nunca_e_apagado(): void
    {
        /*
         * O ponto mais importante da faxina: enquanto ninguém conferiu, o print
         * é a única coisa que permite conferir. Apagá-lo por idade transformaria
         * o card num bilhete sem prova — exatamente o problema que o quadro veio
         * resolver.
         */
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();

        $devolucao->forceFill(['created_at' => now()->subYear()])->save();

        $this->artisan('devolucoes:limpar-anexos')->assertSuccessful();

        Storage::disk(DevolucaoAnexo::DISCO)->assertExists($devolucao->anexos->first()->caminho);
        $this->assertCount(1, $devolucao->fresh()->anexos);
    }

    public function test_arquivo_sai_uma_semana_depois_de_conferido(): void
    {
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();
        $caminho = $devolucao->anexos->first()->caminho;

        $this->actingAs($this->recebimento)->post(route('devolucoes.conferir', $devolucao));

        // Dentro do prazo: a faxina não encosta
        $this->artisan('devolucoes:limpar-anexos')->assertSuccessful();
        Storage::disk(DevolucaoAnexo::DISCO)->assertExists($caminho);

        // Envelhece a conferência para além do prazo
        $devolucao->forceFill([
            'conferida_em' => now()->subDays(Devolucao::DIAS_APOS_CONFERIR + 1),
        ])->save();

        $this->artisan('devolucoes:limpar-anexos')->assertSuccessful();

        Storage::disk(DevolucaoAnexo::DISCO)->assertMissing($caminho);

        // O CARD continua: é o histórico de quem devolveu o quê
        $this->assertDatabaseHas('devolucoes', ['id' => $devolucao->id, 'numero_nota' => '310712']);
        $this->assertCount(0, $devolucao->fresh()->anexos);
    }

    public function test_baixar_arquivo_ja_apagado_responde_410(): void
    {
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();
        $anexo = $devolucao->anexos->first();

        Storage::disk(DevolucaoAnexo::DISCO)->delete($anexo->caminho);

        // 410 e não 404: sumiu por decisão, não por erro — a tela usa isso para
        // explicar em vez de dizer "não encontrado".
        $this->actingAs($this->preLote)
            ->get(route('devolucoes.arquivo', [$devolucao, $anexo]))
            ->assertStatus(410);
    }

    public function test_dias_zero_forca_a_limpeza(): void
    {
        // Em PHP o "0" da linha de comando é FALSO: escrito com `?:`, o comando
        // cairia calado no prazo padrão (a mesma armadilha do chat:limpar-anexos).
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();
        $caminho = $devolucao->anexos->first()->caminho;

        $this->actingAs($this->recebimento)->post(route('devolucoes.conferir', $devolucao));

        $this->artisan('devolucoes:limpar-anexos', ['--dias' => 0])->assertSuccessful();

        Storage::disk(DevolucaoAnexo::DISCO)->assertMissing($caminho);
    }

    // ─── A tela recebe o quadro ───────────────────────────────────────────────

    public function test_a_fila_entrega_o_quadro_para_quem_pode_usar(): void
    {
        $this->lanca($this->preLote);

        $this->actingAs($this->recebimento)
            ->get(route('notas.index'))
            ->assertOk()
            ->assertInertia(fn($pagina) => $pagina
                ->has('devolucoes', 1)
                ->where('devolucoes.0.numero_nota', '310712')
                ->where('devolucoes.0.fornecedor', 'VERDE CAMPO')
                ->has('devolucoes.0.anexos', 1)
                ->where('auth.can.usarDevolucoes', true));
    }

    public function test_compras_ve_a_fila_sem_poder_usar_o_quadro(): void
    {
        $this->actingAs($this->compras)
            ->get(route('notas.index'))
            ->assertOk()
            ->assertInertia(fn($pagina) => $pagina->where('auth.can.usarDevolucoes', false));
    }

    public function test_card_conferido_some_do_quadro_depois_da_semana(): void
    {
        // O quadro mostra o conferido por uma semana — o mesmo prazo dos
        // arquivos — para dar tempo de alguém voltar e reconferir.
        $this->lanca($this->preLote);
        $devolucao = Devolucao::firstOrFail();

        $devolucao->forceFill([
            'conferida_em'  => now()->subDays(Devolucao::DIAS_APOS_CONFERIR + 1),
            'conferida_por' => $this->recebimento->id,
        ])->save();

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($pagina) => $pagina->has('devolucoes', 0));
    }
}
