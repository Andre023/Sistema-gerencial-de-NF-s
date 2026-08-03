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
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
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
}
