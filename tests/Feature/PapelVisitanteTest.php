<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Papel "visitante": acesso só-leitura. Vê a fila de notas, mas não executa
 * nenhuma ação — nem as já gatadas (lançar, liberar...) nem as leves (comentar,
 * reservar "estou olhando"), que antes eram abertas a qualquer logado.
 */
class PapelVisitanteTest extends TestCase
{
    use RefreshDatabase;

    private User $visitante;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->visitante = User::factory()->create(['role' => User::ROLE_VISITANTE]);
        $this->admin     = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function nota(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);
        return Nota::create(array_merge([
            'numero_nota'   => '9001',
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->admin->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    public function test_visitante_ve_a_fila(): void
    {
        $this->actingAs($this->visitante)->get(route('notas.index'))->assertOk();
    }

    public function test_visitante_nao_lanca_nota(): void
    {
        $this->actingAs($this->visitante)
            ->post(route('notas.store'), [
                'numero_nota'   => '123',
                'fornecedor_id' => Fornecedor::firstOrCreate(['nome' => 'X'])->id,
                'loja'          => 1,
                'origem'        => 'recebimento',
            ])
            ->assertForbidden();
    }

    public function test_visitante_nao_libera(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->visitante)->post(route('notas.liberar', $nota))->assertForbidden();
    }

    public function test_visitante_nao_comenta(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->visitante)
            ->postJson(route('notas.comentarios.store', $nota), ['texto' => 'oi'])
            ->assertForbidden();
    }

    public function test_visitante_nao_reserva(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->visitante)->post(route('notas.visualizar', $nota))->assertForbidden();
        $this->assertNull($nota->fresh()->visualizando_por);
    }

    public function test_visitante_nao_acessa_areas_admin(): void
    {
        $this->actingAs($this->visitante)->get(route('estatisticas.index'))->assertForbidden();
        $this->actingAs($this->visitante)->get(route('usuarios.index'))->assertForbidden();
        $this->actingAs($this->visitante)->get(route('prioridades.index'))->assertForbidden();
    }

    public function test_visitante_nao_tem_permissoes(): void
    {
        $v = $this->visitante;
        $this->assertTrue($v->ehVisitante());
        $this->assertFalse($v->podeLancarNota());
        $this->assertFalse($v->podeGerirCards());
        $this->assertFalse($v->podeCorrigirCard());
        $this->assertFalse($v->podeLiberarNota());
        $this->assertFalse($v->podeEditarNotas());
        $this->assertFalse($v->podeDevolverNota());
        $this->assertFalse($v->podeInteragir());
    }

    public function test_admin_cria_visitante(): void
    {
        $this->actingAs($this->admin)
            ->post(route('usuarios.store'), [
                'name'                  => 'Fulano Visitante',
                'email'                 => 'visita@exemplo.com',
                'password'              => 'senha-forte-123',
                'password_confirmation' => 'senha-forte-123',
                'role'                  => 'visitante',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'visita@exemplo.com', 'role' => 'visitante']);
    }
}
