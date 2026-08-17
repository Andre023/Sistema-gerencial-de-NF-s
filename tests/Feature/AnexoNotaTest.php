<?php

namespace Tests\Feature;

use App\Jobs\LimparAnexosDaNota;
use App\Models\Anexo;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documentos e fotos da nota.
 *
 * O que mais importa aqui não é o caminho feliz: é o arquivo NÃO ficar
 * acessível sem login, NÃO sobreviver à saída da nota, e NÃO aceitar o que
 * pode virar script na origem do sistema.
 */
class AnexoNotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Anexo::DISCO);
    }

    private function nota(array $extra = []): Nota
    {
        return Nota::create([
            'numero_nota'   => '99001',
            // firstOrCreate: fornecedores.nome é único, e vários testes aqui
            // criam mais de uma nota
            'fornecedor_id' => Fornecedor::firstOrCreate(['nome' => 'FORNECEDOR TESTE'])->id,
            'user_id'       => User::factory()->create(['role' => User::ROLE_RECEBIMENTO])->id,
            'loja'          => Nota::LOJAS[0],
            'origem'        => 'recebimento',
            ...$extra,
        ]);
    }

    private function foto(string $nome = 'canhoto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nome, 800, 600);
    }

    private function envia(User $user, Nota $nota, ?UploadedFile $arquivo = null)
    {
        return $this->actingAs($user)->post(
            route('notas.anexos.store', $nota),
            ['arquivo' => $arquivo ?? $this->foto()],
        );
    }

    /** Anexo criado sem HTTP, para testar quem NÃO está autenticado. */
    private function anexoDireto(Nota $nota): Anexo
    {
        $caminho = "anexos/{$nota->id}/" . \Illuminate\Support\Str::uuid() . '.jpg';
        Storage::disk(Anexo::DISCO)->put($caminho, 'conteudo-de-teste');

        return $nota->anexos()->create([
            'caminho'       => $caminho,
            'nome_original' => 'canhoto.jpg',
            'mime'          => 'image/jpeg',
            'tamanho'       => 17,
            'enviado_por'   => User::factory()->create(['role' => User::ROLE_RECEBIMENTO])->id,
        ]);
    }

    /**
     * UploadedFile REAL (não `fake()`), para a validação farejar o conteúdo.
     *
     * O `fake()` do Laravel devolve um Testing\File que sobrescreve
     * getMimeType() e responde o tipo DECLARADO. Com ele, um .jpg cheio de PHP
     * passaria no `mimes:` — não porque a regra falha, mas porque o teste nunca
     * chega a exercitá-la. Aqui o arquivo é de verdade e o finfo olha os bytes.
     */
    private function arquivoReal(string $nome, string $conteudo, string $mimeDeclarado): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'anexo');
        file_put_contents($tmp, $conteudo);

        return new UploadedFile($tmp, $nome, $mimeDeclarado, null, true);
    }

    // ─── Permissão ────────────────────────────────────────────────────────────

    public function test_recebimento_e_pre_lote_anexam(): void
    {
        foreach ([User::ROLE_RECEBIMENTO, User::ROLE_PRE_LOTE] as $papel) {
            $nota = $this->nota(['numero_nota' => "nota-{$papel}"]);
            $user = User::factory()->create(['role' => $papel]);

            $this->envia($user, $nota)->assertCreated();

            $this->assertCount(1, $nota->anexos()->get());
            Storage::disk(Anexo::DISCO)->assertExists($nota->anexos()->first()->caminho);
        }
    }

    /**
     * Compras TAMBÉM anexa.
     *
     * Era travada por não ter a mercadoria na mão. Na prática o bloqueio pegava
     * justamente quando ela tinha o que mostrar: o print do pedido no ERP, o
     * e-mail do fornecedor, a foto que o representante mandou. Via o anexo dos
     * outros e não podia responder com um.
     */
    public function test_compras_anexa(): void
    {
        $nota = $this->nota();

        $this->envia(User::factory()->create(['role' => User::ROLE_COMPRAS]), $nota)
            ->assertCreated();

        $this->assertCount(1, $nota->anexos()->get());
    }

    /** O visitante continua de fora: a conta existe para olhar, não para agir. */
    public function test_visitante_nao_anexa(): void
    {
        $nota = $this->nota();

        $this->envia(User::factory()->create(['role' => User::ROLE_VISITANTE]), $nota)
            ->assertForbidden();

        $this->assertCount(0, $nota->anexos()->get());
    }

    /** E o visitante também não REMOVE o que os outros anexaram. */
    public function test_visitante_nao_remove(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $anexo = $nota->anexos()->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_VISITANTE]))
            ->delete(route('notas.anexos.destroy', [$nota, $anexo]))
            ->assertForbidden();

        $this->assertCount(1, $nota->anexos()->get());
    }

    public function test_compras_consegue_ver_e_baixar(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $anexo = $nota->anexos()->first();

        $compras = User::factory()->create(['role' => User::ROLE_COMPRAS]);

        $this->actingAs($compras)->get(route('notas.anexos.index', $nota))->assertOk();
        $this->actingAs($compras)
            ->get(route('notas.anexos.download', [$nota, $anexo]))
            ->assertOk();
    }

    // ─── A trava que importa: nada sai sem login ──────────────────────────────

    public function test_anonimo_nao_alcanca_o_arquivo(): void
    {
        $nota = $this->nota();

        // Criado direto, sem passar por actingAs: o usuário autenticado de um
        // request de teste continua valendo nos seguintes, e o "anônimo" deste
        // teste sairia logado — provando exatamente nada.
        $anexo = $this->anexoDireto($nota);

        $this->get(route('notas.anexos.download', [$nota, $anexo]))
            ->assertRedirect(route('login'));

        $this->get(route('notas.anexos.index', $nota))
            ->assertRedirect(route('login'));
    }

    /** O arquivo não pode estar sob public/, senão o nginx o serve direto. */
    public function test_arquivo_fica_fora_da_pasta_publica(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);

        $caminho = $nota->anexos()->first()->caminho;

        $this->assertStringNotContainsString('public', $caminho);
        $this->assertFileDoesNotExist(public_path($caminho));
    }

    // ─── Formato: o que pode virar script não entra ───────────────────────────

    public function test_svg_e_recusado(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $this->nota(), $svg)
            ->assertSessionHasErrors('arquivo');
    }

    /**
     * PHP com nome de imagem e Content-Type de imagem: o que decide é o
     * conteúdo. Este é o teste que justifica usar `mimes:` e não `mimetypes:`.
     */
    public function test_php_disfarcado_de_imagem_e_recusado(): void
    {
        $falso = $this->arquivoReal('foto.jpg', '<?php system($_GET["c"]); ?>', 'image/jpeg');

        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $this->nota(), $falso)
            ->assertSessionHasErrors('arquivo');
    }

    /** O mesmo para SVG travestido de PNG — XML com script dentro. */
    public function test_svg_disfarcado_de_png_e_recusado(): void
    {
        $falso = $this->arquivoReal(
            'imagem.png',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'image/png',
        );

        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $this->nota(), $falso)
            ->assertSessionHasErrors('arquivo');
    }

    public function test_arquivo_grande_demais_e_recusado(): void
    {
        $grande = UploadedFile::fake()->create('scan.pdf', Anexo::TAMANHO_MAX_KB + 1024, 'application/pdf');

        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $this->nota(), $grande)
            ->assertSessionHasErrors('arquivo');
    }

    /**
     * Nome com travessia de diretório não decide onde o arquivo é gravado.
     *
     * A extensão precisa ser válida (.jpg) para o teste chegar ao ponto que
     * interessa: com um nome tipo "../../.env" a recusa viria do formato, e o
     * caminho — que é o que este teste examina — nunca seria exercitado.
     */
    public function test_nome_malicioso_nao_escolhe_o_caminho(): void
    {
        $nota = $this->nota();

        $this->envia(
            User::factory()->create(['role' => User::ROLE_RECEBIMENTO]),
            $nota,
            UploadedFile::fake()->image('../../../../evil.jpg', 400, 300),
        )->assertCreated();

        $anexo = $nota->anexos()->first();

        $this->assertStringStartsWith("anexos/{$nota->id}/", $anexo->caminho);
        $this->assertStringNotContainsString('..', $anexo->caminho);
        $this->assertStringNotContainsString('/', $anexo->nome_original);
    }

    // ─── Isolamento entre notas ───────────────────────────────────────────────

    public function test_anexo_de_outra_nota_da_404(): void
    {
        $notaA = $this->nota(['numero_nota' => 'A-1']);
        $notaB = $this->nota(['numero_nota' => 'B-1']);
        $user  = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $this->envia($user, $notaA);
        $anexoDeA = $notaA->anexos()->first();

        $this->actingAs($user)
            ->get(route('notas.anexos.download', [$notaB, $anexoDeA]))
            ->assertNotFound();
    }

    // ─── Saídas da nota: o arquivo tem de sumir ───────────────────────────────

    public function test_cancelar_nota_apaga_os_anexos_na_hora(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $caminho = $nota->anexos()->first()->caminho;

        $this->actingAs(User::factory()->create(['role' => User::ROLE_PRE_LOTE]))
            ->post(route('notas.cancelar', $nota), ['motivo' => 'fornecedor cancelou'])
            ->assertRedirect();

        Storage::disk(Anexo::DISCO)->assertMissing($caminho);
        $this->assertCount(0, $nota->anexos()->get());
    }

    public function test_excluir_nota_apaga_os_anexos_na_hora(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $caminho = $nota->anexos()->first()->caminho;

        $this->actingAs(User::factory()->create(['role' => User::ROLE_PRE_LOTE]))
            ->delete(route('notas.destroy', $nota))
            ->assertRedirect();

        Storage::disk(Anexo::DISCO)->assertMissing($caminho);
    }

    public function test_liberar_agenda_a_limpeza_para_dois_dias(): void
    {
        Queue::fake();

        $nota = $this->nota();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_PRE_LOTE]))
            ->post(route('notas.liberar', $nota))
            ->assertRedirect();

        Queue::assertPushed(LimparAnexosDaNota::class,
            fn(LimparAnexosDaNota $job) => $job->notaId === $nota->id);
    }

    public function test_job_apaga_quando_passaram_os_dois_dias(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $caminho = $nota->anexos()->first()->caminho;

        $nota->update(['liberada_em' => now()->subDays(3)]);

        (new LimparAnexosDaNota($nota->id))->handle();

        Storage::disk(Anexo::DISCO)->assertMissing($caminho);
        $this->assertCount(0, $nota->anexos()->get());
    }

    /**
     * A reconferência do job. A nota foi devolvida (voltou para a fila) depois
     * de a limpeza ter sido agendada — as fotos são necessárias de novo.
     */
    public function test_job_nao_apaga_nota_que_voltou_para_a_fila(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $caminho = $nota->anexos()->first()->caminho;

        $nota->update(['liberada_em' => null]); // devolvida

        (new LimparAnexosDaNota($nota->id))->handle();

        Storage::disk(Anexo::DISCO)->assertExists($caminho);
        $this->assertCount(1, $nota->anexos()->get());
    }

    /** Liberada há pouco: o job de uma liberação anterior não pode adiantar. */
    public function test_job_nao_apaga_antes_do_prazo(): void
    {
        $nota = $this->nota(['liberada_em' => now()->subHours(5)]);
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $caminho = $nota->anexos()->first()->caminho;

        (new LimparAnexosDaNota($nota->id))->handle();

        Storage::disk(Anexo::DISCO)->assertExists($caminho);
    }

    // ─── Remoção manual ───────────────────────────────────────────────────────

    public function test_pre_lote_remove_anexo(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $anexo = $nota->anexos()->first();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_PRE_LOTE]))
            ->delete(route('notas.anexos.destroy', [$nota, $anexo]))
            ->assertOk();

        Storage::disk(Anexo::DISCO)->assertMissing($anexo->caminho);
    }

    public function test_visitante_nao_remove_anexo(): void
    {
        $nota = $this->nota();
        $this->envia(User::factory()->create(['role' => User::ROLE_RECEBIMENTO]), $nota);
        $anexo = $nota->anexos()->first();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_VISITANTE]))
            ->delete(route('notas.anexos.destroy', [$nota, $anexo]))
            ->assertForbidden();

        Storage::disk(Anexo::DISCO)->assertExists($anexo->caminho);
    }
}
