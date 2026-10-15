<?php

namespace Tests\Feature;

use App\Models\Mensagem;
use App\Models\User;
use App\Services\Conversas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Hipermon — o perfil que conta as novidades do sistema no chat.
 *
 * O que se protege aqui:
 *   • a conta existe (a migration cria) e tem o robô como avatar
 *   • ninguém entra com ela, ninguém escreve para ela, o admin não a edita
 *   • publicar manda a mesma mensagem, do Hipermon, para cada conta
 *   • só o admin publica
 */
class HipermonTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $ana;
    private User $carlos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin  = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->ana    = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->carlos = User::factory()->create(['role' => User::ROLE_COMPRAS]);
    }

    private function hipermon(): User
    {
        return User::hipermon();
    }

    public function test_a_migration_cria_o_hipermon_com_o_robo(): void
    {
        $h = $this->hipermon();

        $this->assertNotNull($h);
        $this->assertSame('Hipermon', $h->name);
        $this->assertSame(['tipo' => 'emoji', 'valor' => '🤖'], $h->avatar);
        $this->assertNotContains(User::ROLE_SISTEMA, User::ROLES);
    }

    public function test_ninguem_entra_com_o_hipermon(): void
    {
        // Mesmo sabendo a senha — aqui forçada só para o teste
        $this->hipermon()->update(['password' => 'senha-conhecida']);

        $this->post('/login', ['email' => User::HIPERMON_EMAIL, 'password' => 'senha-conhecida'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_ninguem_escreve_para_o_hipermon(): void
    {
        $this->actingAs($this->ana)
            ->postJson(route('conversas.enviar', $this->hipermon()), ['texto' => 'oi robô'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('texto');

        $this->assertDatabaseMissing('mensagens', ['texto' => 'oi robô']);
    }

    public function test_o_hipermon_nao_aparece_em_usuarios_nem_pode_ser_editado(): void
    {
        $this->actingAs($this->admin)
            ->get(route('usuarios.index'))
            ->assertInertia(fn($page) => $page->where('usuarios', fn($lista) =>
                collect($lista)->doesntContain('role', User::ROLE_SISTEMA)));

        $this->actingAs($this->admin)
            ->patch(route('usuarios.update', $this->hipermon()), ['role' => User::ROLE_ADMIN])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('usuarios.destroy', $this->hipermon()))
            ->assertNotFound();

        $this->assertSame(User::ROLE_SISTEMA, $this->hipermon()->fresh()->role);
    }

    public function test_publicar_manda_para_cada_conta(): void
    {
        $this->actingAs($this->admin)
            ->post(route('configuracoes.novidades.publicar'), ['texto' => 'Novidade: card Bluesoft!'])
            ->assertRedirect()
            ->assertSessionHas('sucesso', 'Novidade enviada para 3 pessoas.');

        $h = $this->hipermon();

        // Uma mensagem por pessoa (admin, ana, carlos), todas do Hipermon
        $this->assertSame(3, Mensagem::where('user_id', $h->id)->where('texto', 'Novidade: card Bluesoft!')->count());

        // E chega como não lida na conversa de cada um
        foreach ([$this->admin, $this->ana, $this->carlos] as $pessoa) {
            $this->assertSame(1, Conversas::naoLidasDe($pessoa));
        }
    }

    public function test_o_hipermon_aparece_no_chat_como_sistema(): void
    {
        $lista = Conversas::paraUsuario($this->ana)['pessoas'];

        $this->assertContains('sistema', array_column($lista, 'papel'));
    }

    public function test_so_o_admin_publica(): void
    {
        $this->actingAs($this->ana)
            ->post(route('configuracoes.novidades.publicar'), ['texto' => 'eu também quero'])
            ->assertForbidden();

        $this->assertDatabaseMissing('mensagens', ['texto' => 'eu também quero']);
    }

    public function test_novidade_vazia_e_recusada(): void
    {
        $this->actingAs($this->admin)
            ->post(route('configuracoes.novidades.publicar'), ['texto' => '   '])
            ->assertSessionHasErrors('texto');
    }

    public function test_a_tela_mostra_o_historico(): void
    {
        $this->actingAs($this->admin)
            ->post(route('configuracoes.novidades.publicar'), ['texto' => 'Primeira novidade']);

        $this->actingAs($this->admin)
            ->get(route('configuracoes.novidades'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->component('Configuracoes/Novidades')
                ->where('pessoas', 3)
                ->where('historico.0.texto', 'Primeira novidade')
                ->where('historico.0.pessoas', 3));
    }
}
