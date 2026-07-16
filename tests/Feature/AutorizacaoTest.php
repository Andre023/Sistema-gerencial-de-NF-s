<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Requisicao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    private function requisicao(User $dono): Requisicao
    {
        $forn = Fornecedor::create(['nome' => 'FORNECEDOR TESTE']);

        return Requisicao::create([
            'numero_nota'   => '123',
            'fornecedor_id' => $forn->id,
            'user_id'       => $dono->id,
            'loja'          => 1,
            'motivo'        => 'Preço',
            'status'        => 'Pendente',
        ]);
    }

    // ── Atender (mudar só o status) é liberado a todos ──────────────────────────

    public function test_operador_pode_atender(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req = $this->requisicao($operador);

        $this->actingAs($operador)
            ->patch(route('requisicoes.update', $req), ['status' => 'Atendida'])
            ->assertRedirect();

        $req->refresh();
        $this->assertSame('Atendida', $req->status);
        $this->assertSame($operador->id, $req->atendida_por);
        $this->assertNotNull($req->atendida_em);
    }

    // ── Editar campos e excluir exigem encarregado+ ────────────────────────────

    public function test_operador_nao_pode_editar_campos(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req = $this->requisicao($operador);

        $this->actingAs($operador)
            ->patch(route('requisicoes.update', $req), ['numero_nota' => '999'])
            ->assertForbidden();
    }

    public function test_operador_nao_pode_excluir(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req = $this->requisicao($operador);

        $this->actingAs($operador)
            ->delete(route('requisicoes.destroy', $req))
            ->assertForbidden();

        $this->assertNull($req->fresh()->deleted_at);
    }

    public function test_encarregado_pode_excluir(): void
    {
        $encarregado = User::factory()->create(['role' => User::ROLE_ENCARREGADO]);
        $req = $this->requisicao($encarregado);

        $this->actingAs($encarregado)
            ->delete(route('requisicoes.destroy', $req))
            ->assertRedirect();

        $this->assertNotNull($req->fresh()->deleted_at);
    }

    // ── Estatísticas e Usuários são só de admin ────────────────────────────────

    public function test_estatisticas_somente_admin(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $admin    = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Operador é barrado pelo middleware (403) antes de qualquer query.
        $this->actingAs($operador)->get(route('estatisticas.index'))->assertForbidden();

        // Admin passa na autorização (o render usa SQL específico do MySQL, então
        // aqui validamos apenas o gate, não a renderização da página).
        $this->assertTrue(Gate::forUser($admin)->allows('ver-estatisticas'));
        $this->assertFalse(Gate::forUser($operador)->allows('ver-estatisticas'));
    }

    public function test_gestao_de_usuarios_somente_admin(): void
    {
        $encarregado = User::factory()->create(['role' => User::ROLE_ENCARREGADO]);
        $admin       = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($encarregado)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('usuarios.index'))->assertOk();
    }

    public function test_admin_cria_usuario(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('usuarios.store'), [
            'name'                  => 'Novo Operador',
            'email'                 => 'novo@sistema.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => User::ROLE_OPERADOR,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'novo@sistema.com',
            'role'  => User::ROLE_OPERADOR,
        ]);
    }
}
