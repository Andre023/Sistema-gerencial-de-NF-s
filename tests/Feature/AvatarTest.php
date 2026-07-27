<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avatar personalizado: emoji (com tom de pele) e monograma (cor ou automático).
 * O objeto `avatar` é anexado a todo User serializado.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(): User
    {
        return User::factory()->create(['role' => User::ROLE_COMPRAS]);
    }

    public function test_define_emoji(): void
    {
        $u = $this->usuario();

        $this->actingAs($u)
            ->patch(route('profile.avatar'), ['tipo' => 'emoji', 'valor' => '🧑🏽‍💼'])
            ->assertRedirect(route('profile.edit'));

        $u->refresh();
        $this->assertSame('emoji', $u->avatar_tipo);
        $this->assertSame('🧑🏽‍💼', $u->avatar_valor);
        $this->assertSame('emoji', $u->avatar['tipo']); // accessor anexado
    }

    public function test_define_monograma_com_cor(): void
    {
        $u = $this->usuario();

        $this->actingAs($u)
            ->patch(route('profile.avatar'), ['tipo' => 'monograma', 'valor' => '#8250df'])
            ->assertRedirect();

        $u->refresh();
        $this->assertSame('monograma', $u->avatar_tipo);
        $this->assertSame('#8250df', $u->avatar_valor);
    }

    public function test_monograma_automatico_aceita_valor_nulo(): void
    {
        $u = $this->usuario();

        $this->actingAs($u)
            ->patch(route('profile.avatar'), ['tipo' => 'monograma', 'valor' => null])
            ->assertRedirect();

        $this->assertNull($u->fresh()->avatar_valor);
    }

    public function test_tipo_invalido_e_recusado(): void
    {
        $u = $this->usuario();

        $this->actingAs($u)
            ->patch(route('profile.avatar'), ['tipo' => 'gif'])
            ->assertSessionHasErrors('tipo');
    }

    public function test_foto_nao_e_um_tipo_valido(): void
    {
        $u = $this->usuario();

        $this->actingAs($u)
            ->patch(route('profile.avatar'), ['tipo' => 'foto'])
            ->assertSessionHasErrors('tipo');
    }

    public function test_avatar_e_exposto_como_objeto(): void
    {
        $u = $this->usuario();

        // Sem escolher nada, o default é monograma com valor nulo
        $avatar = $u->avatar;
        $this->assertSame('monograma', $avatar['tipo']);
        $this->assertNull($avatar['valor']);
        $this->assertArrayNotHasKey('foto', $avatar);
    }
}
