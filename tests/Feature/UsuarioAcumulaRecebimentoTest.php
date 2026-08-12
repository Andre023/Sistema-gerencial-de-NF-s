<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A marca "também faz recebimento", na tela de Usuários.
 *
 * O efeito dela vive em NotaLancadaAvisoTest; aqui garantimos que o admin
 * consegue ligá-la e desligá-la de verdade — de nada adianta a regra existir
 * se o formulário não grava.
 */
class UsuarioAcumulaRecebimentoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_admin_cria_usuario_ja_marcado(): void
    {
        $this->actingAs($this->admin)
            ->post(route('usuarios.store'), [
                'name'                  => 'Liliane',
                'email'                 => 'liliane@exemplo.com',
                'password'              => 'senha-forte-123',
                'password_confirmation' => 'senha-forte-123',
                'role'                  => User::ROLE_PRE_LOTE,
                'acumula_recebimento'   => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'liliane@exemplo.com')->first()->acumula_recebimento);
    }

    public function test_admin_marca_e_desmarca_em_usuario_existente(): void
    {
        $igor = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);

        $this->actingAs($this->admin)
            ->patch(route('usuarios.update', $igor), ['acumula_recebimento' => true])
            ->assertRedirect();

        $this->assertTrue($igor->fresh()->acumula_recebimento);

        $this->actingAs($this->admin)
            ->patch(route('usuarios.update', $igor), ['acumula_recebimento' => false])
            ->assertRedirect();

        $this->assertFalse($igor->fresh()->acumula_recebimento);
    }

    /** Editar o nome não pode apagar a marca sem querer. */
    public function test_editar_outro_campo_preserva_a_marca(): void
    {
        $igor = User::factory()->create([
            'role' => User::ROLE_PRE_LOTE,
            'acumula_recebimento' => true,
        ]);

        $this->actingAs($this->admin)
            ->patch(route('usuarios.update', $igor), ['name' => 'Igor da Silva'])
            ->assertRedirect();

        $this->assertTrue($igor->fresh()->acumula_recebimento);
        $this->assertSame('Igor da Silva', $igor->fresh()->name);
    }

    public function test_a_tela_recebe_a_marca_de_cada_usuario(): void
    {
        User::factory()->create([
            'name' => 'Igor',
            'role' => User::ROLE_PRE_LOTE,
            'acumula_recebimento' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('usuarios.index'))
            ->assertInertia(fn($page) => $page
                ->where('usuarios', fn($usuarios) => collect($usuarios)
                    ->firstWhere('name', 'Igor')['acumula_recebimento'] === true));
    }

    /** Quem não é admin não mexe nisso — a rota inteira é de admin. */
    public function test_papel_operacional_nao_altera_a_marca(): void
    {
        $igor = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);

        $this->actingAs($preLote)
            ->patch(route('usuarios.update', $igor), ['acumula_recebimento' => true])
            ->assertForbidden();

        $this->assertFalse($igor->fresh()->acumula_recebimento);
    }
}
