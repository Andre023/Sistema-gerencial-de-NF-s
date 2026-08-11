<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Travas que não pertencem a nenhuma tela — por isso passavam despercebidas.
 *
 * O caso da importação de fornecedores é o exemplo: como não existe botão para
 * ela em lugar nenhum, ninguém notava que a rota respondia a qualquer conta
 * autenticada, visitante inclusive.
 */
class SegurancaTest extends TestCase
{
    use RefreshDatabase;

    /** JSON no formato que o endpoint de importação espera. */
    private function arquivo(array $fornecedores): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'fornecedores.json',
            json_encode($fornecedores),
        );
    }

    // ─── Importar fornecedores: upsert em massa é ato de admin ────────────────

    public function test_admin_importa_fornecedores(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('fornecedores.importar'), [
                'arquivo' => $this->arquivo([['nome' => 'DOCES VIERA']]),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('fornecedores', ['nome' => 'DOCES VIERA']);
    }

    /**
     * Nenhum papel operacional importa — nem o visitante (só leitura), nem
     * quem mexe com nota o dia inteiro. O JSON errado renomearia a base toda.
     */
    public function test_nenhum_papel_operacional_importa_fornecedores(): void
    {
        $papeis = [
            User::ROLE_VISITANTE,
            User::ROLE_RECEBIMENTO,
            User::ROLE_PRE_LOTE,
            User::ROLE_COMPRAS,
        ];

        foreach ($papeis as $papel) {
            $user = User::factory()->create(['role' => $papel]);

            $this->actingAs($user)
                ->post(route('fornecedores.importar'), [
                    'arquivo' => $this->arquivo([['nome' => 'INVASOR ' . $papel]]),
                ])
                ->assertForbidden();

            $this->assertDatabaseMissing('fornecedores', ['nome' => 'INVASOR ' . $papel]);
        }
    }

    public function test_visitante_nao_renomeia_fornecedor_existente(): void
    {
        $forn = Fornecedor::create(['nome' => 'CHUA', 'cnpj' => '00.000.000/0001-00']);
        $visitante = User::factory()->create(['role' => User::ROLE_VISITANTE]);

        $this->actingAs($visitante)
            ->post(route('fornecedores.importar'), [
                'arquivo' => $this->arquivo([['nome' => 'CHUA', 'cnpj' => '99.999.999/9999-99']]),
            ])
            ->assertForbidden();

        $this->assertSame('00.000.000/0001-00', $forn->fresh()->cnpj);
    }

    /**
     * O JSON vem de fora e nada garante o formato. Antes, "nome" chegando como
     * lista derrubava a importação com erro 500 no meio de um upsert em massa.
     */
    public function test_importacao_aguenta_json_malformado(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('fornecedores.importar'), [
                'arquivo' => $this->arquivo([
                    ['nome' => ['virou', 'lista']],
                    ['nome' => 12345],
                    ['nome' => '   '],
                    ['cnpj' => 'sem nome'],
                    'linha solta',
                    ['nome' => 'FORNECEDOR BOM', 'cnpj' => ['tambem lista']],
                ]),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Só o registro válido entra — e sem o cnpj, que veio quebrado
        $this->assertDatabaseHas('fornecedores', ['nome' => 'FORNECEDOR BOM', 'cnpj' => null]);
        $this->assertSame(1, Fornecedor::count());
    }

    // ─── Porta de entrada: sem página pública ─────────────────────────────────

    public function test_raiz_manda_visitante_anonimo_para_o_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_raiz_manda_quem_esta_logado_para_a_fila(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $this->actingAs($user)->get('/')->assertRedirect(route('notas.index'));
    }

    /** A splash do Laravel anunciava a versão do framework e do PHP sem login. */
    public function test_raiz_nao_expoe_a_versao_do_framework(): void
    {
        $resposta = $this->get('/');

        $resposta->assertDontSee(app()->version());
        $resposta->assertDontSee(PHP_VERSION);
    }

    // ─── Cabeçalhos de segurança ──────────────────────────────────────────────

    public function test_cabecalhos_de_seguranca_em_toda_resposta(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $this->actingAs($user)->get(route('notas.index'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    // ─── Content-Security-Policy ──────────────────────────────────────────────

    /**
     * O script-src é o que impede HTML injetado de rodar. Se um dia alguém
     * afrouxar para 'unsafe-inline' ou '*', a CSP vira enfeite — daí o teste
     * olhar o conteúdo da diretiva, não só a presença do cabeçalho.
     */
    public function test_csp_tranca_a_execucao_de_script(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $csp = $this->actingAs($user)->get(route('notas.index'))
            ->assertHeader('Content-Security-Policy')
            ->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[A-Za-z0-9]{20,}'/", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);

        foreach (["frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", "form-action 'self'"] as $diretiva) {
            $this->assertStringContainsString($diretiva, $csp);
        }
    }

    /** O nonce do cabeçalho e o do <script> do Ziggy têm de ser o MESMO. */
    public function test_nonce_do_cabecalho_bate_com_o_da_pagina(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $resposta = $this->actingAs($user)->get(route('notas.index'));

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $resposta->headers->get('Content-Security-Policy'), $doCabecalho);
        $this->assertNotEmpty($doCabecalho[1] ?? null, 'a CSP deveria trazer um nonce');

        $this->assertStringContainsString('nonce="' . $doCabecalho[1] . '"', $resposta->getContent());
    }

    /** Nonce reaproveitado entre respostas não vale de nada — tem de mudar. */
    public function test_nonce_muda_a_cada_carregamento(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $pega = fn() => $this->actingAs($user)->get(route('notas.index'))
            ->headers->get('Content-Security-Policy');

        $this->assertNotSame($pega(), $pega());
    }

    /** HSTS só faz sentido (e só é enviado) quando a requisição já veio por HTTPS. */
    public function test_hsts_apenas_em_https(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $this->actingAs($user)->get(route('notas.index'))
            ->assertHeaderMissing('Strict-Transport-Security');

        // O esquema tem de estar na própria URL: o Symfony reescreve a variável
        // HTTPS a partir dela, então mandá-la por fora não teria efeito.
        $this->actingAs($user)
            ->get('https://localhost' . route('notas.index', absolute: false))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    // ─── Exclusão da própria conta (a porta dos fundos do Perfil) ─────────────

    /**
     * A tela de Usuários já barrava o rebaixamento/exclusão do último admin,
     * mas o Perfil não olhava nada: o próprio admin se apagava e o sistema
     * ficava sem quem cria usuário e vê as estatísticas.
     */
    public function test_unico_admin_nao_apaga_a_propria_conta(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password' => bcrypt('senha-de-teste'),
        ]);

        $this->actingAs($admin)
            ->delete(route('profile.destroy'), ['password' => 'senha-de-teste'])
            ->assertSessionHasErrors('conta');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertAuthenticatedAs($admin);
    }

    /** Havendo outro admin, apagar a própria conta volta a ser permitido. */
    public function test_admin_apaga_a_propria_conta_quando_ha_outro(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password' => bcrypt('senha-de-teste'),
        ]);

        $this->actingAs($admin)
            ->delete(route('profile.destroy'), ['password' => 'senha-de-teste'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
    }

    /** Com nota lançada a FK é restritiva: antes isso estourava depois do logout. */
    public function test_quem_tem_nota_lancada_nao_apaga_a_propria_conta(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_RECEBIMENTO,
            'password' => bcrypt('senha-de-teste'),
        ]);

        \App\Models\Nota::create([
            'numero_nota'   => '12345',
            'fornecedor_id' => Fornecedor::create(['nome' => 'TESTE'])->id,
            'user_id'       => $user->id,
            'loja'          => \App\Models\Nota::LOJAS[0],
            'origem'        => 'recebimento',
        ]);

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'senha-de-teste'])
            ->assertSessionHasErrors('conta');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    // ─── Força bruta nas rotas de senha ───────────────────────────────────────

    /** Sem limite dava para varrer quem tem conta e inundar caixas de e-mail. */
    public function test_recuperacao_de_senha_tem_limite_de_tentativas(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('password.email'), ['email' => "chute{$i}@exemplo.com"]);
        }

        $this->post(route('password.email'), ['email' => 'chute-final@exemplo.com'])
            ->assertStatus(429);
    }

    // ─── Entrada da URL: filtro de data ───────────────────────────────────────

    /**
     * ?data= ia direto para Carbon::parse() no cálculo da idade das notas.
     * Qualquer texto que o Carbon não entendesse derrubava a fila com 500 —
     * bastava um link para tirar a tela do ar para quem clicasse.
     */
    public function test_data_invalida_nao_derruba_a_fila(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $lixos = ['lixo', '0000-00-00', '2026-02-31', '../../etc/passwd', "2026-08-10' OR '1'='1", ''];

        foreach ($lixos as $lixo) {
            $this->actingAs($user)
                ->get(route('notas.index', ['data' => $lixo]))
                ->assertOk();
        }
    }

    /** Array onde se espera texto: outro jeito de fazer o parse estourar. */
    public function test_data_como_array_nao_derruba_a_fila(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $this->actingAs($user)
            ->get(route('notas.index') . '?data[]=2026-08-10')
            ->assertOk();
    }

    /** Data boa continua valendo — a validação não pode engolir o filtro real. */
    public function test_data_valida_continua_sendo_respeitada(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $this->actingAs($user)
            ->get(route('notas.index', ['data' => '2026-03-14']))
            ->assertOk()
            ->assertInertia(fn($page) => $page->where('dataFiltro', '2026-03-14'));
    }
}
