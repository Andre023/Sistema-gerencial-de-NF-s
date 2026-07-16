<?php

namespace Tests\Feature;

use App\Models\Comentario;
use App\Models\Fornecedor;
use App\Models\Requisicao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComentarioTest extends TestCase
{
    use RefreshDatabase;

    private function requisicao(User $dono): Requisicao
    {
        // firstOrCreate: fornecedores.nome é único, então chamadas repetidas reusam
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORNECEDOR TESTE']);

        return Requisicao::create([
            'numero_nota'   => '123',
            'fornecedor_id' => $forn->id,
            'user_id'       => $dono->id,
            'loja'          => 1,
            'motivo'        => 'Preço',
            'status'        => 'Pendente',
        ]);
    }

    private function comentario(Requisicao $req, User $autor, string $texto = 'Liguei pro fornecedor'): Comentario
    {
        return $req->comentarios()->create(['user_id' => $autor->id, 'texto' => $texto]);
    }

    // ── O motivo de existir: o operador não edita campos, mas precisa dar contexto ──

    public function test_operador_pode_comentar_mesmo_sem_poder_editar(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req = $this->requisicao($operador);

        // Confirma a premissa: editar campos continua barrado para o operador
        $this->actingAs($operador)
            ->patch(route('requisicoes.update', $req), ['observacao' => 'tentando editar'])
            ->assertForbidden();

        // Mas comentar é liberado
        $this->actingAs($operador)
            ->postJson(route('requisicoes.comentarios.store', $req), ['texto' => 'Fornecedor retorna amanhã'])
            ->assertCreated();

        $this->assertDatabaseHas('comentarios', [
            'comentavel_type' => Requisicao::class,
            'comentavel_id'   => $req->id,
            'user_id'         => $operador->id,
            'texto'           => 'Fornecedor retorna amanhã',
        ]);
    }

    public function test_texto_e_obrigatorio(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req = $this->requisicao($operador);

        $this->actingAs($operador)
            ->postJson(route('requisicoes.comentarios.store', $req), ['texto' => '   '])
            ->assertJsonValidationErrors('texto');
    }

    // ── Timeline mescla auditoria + comentários em ordem cronológica ───────────

    public function test_timeline_mescla_auditoria_e_comentarios(): void
    {
        $encarregado = User::factory()->create(['role' => User::ROLE_ENCARREGADO]);
        $forn = Fornecedor::create(['nome' => 'FORN']);

        // store() gera a auditoria "criada"
        $this->actingAs($encarregado)->post(route('requisicoes.store'), [
            'numero_nota'   => '777',
            'fornecedor_id' => $forn->id,
            'loja'          => 1,
            'motivo'        => 'Preço',
        ])->assertRedirect();

        $req = Requisicao::where('numero_nota', '777')->firstOrFail();
        $this->comentario($req, $encarregado, 'Comentário de teste');

        $resp = $this->actingAs($encarregado)
            ->getJson(route('requisicoes.comentarios.index', $req))
            ->assertOk();

        $timeline = $resp->json('timeline');

        $this->assertCount(2, $timeline);
        $this->assertSame('evento', $timeline[0]['tipo']);
        $this->assertSame('criou a requisição', $timeline[0]['acao']);
        $this->assertSame('comentario', $timeline[1]['tipo']);
        $this->assertSame('Comentário de teste', $timeline[1]['texto']);
    }

    // ── Exclusão ──────────────────────────────────────────────────────────────

    public function test_autor_pode_excluir_o_proprio_comentario(): void
    {
        $operador = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req = $this->requisicao($operador);
        $c = $this->comentario($req, $operador);

        $this->actingAs($operador)
            ->deleteJson(route('requisicoes.comentarios.destroy', [$req, $c]))
            ->assertOk();

        $this->assertDatabaseMissing('comentarios', ['id' => $c->id]);
    }

    public function test_operador_nao_exclui_comentario_de_outro(): void
    {
        $autor  = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $outro  = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req    = $this->requisicao($autor);
        $c      = $this->comentario($req, $autor);

        $this->actingAs($outro)
            ->deleteJson(route('requisicoes.comentarios.destroy', [$req, $c]))
            ->assertForbidden();

        $this->assertDatabaseHas('comentarios', ['id' => $c->id]);
    }

    public function test_encarregado_exclui_comentario_de_qualquer_um(): void
    {
        $autor       = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $encarregado = User::factory()->create(['role' => User::ROLE_ENCARREGADO]);
        $req         = $this->requisicao($autor);
        $c           = $this->comentario($req, $autor);

        $this->actingAs($encarregado)
            ->deleteJson(route('requisicoes.comentarios.destroy', [$req, $c]))
            ->assertOk();

        $this->assertDatabaseMissing('comentarios', ['id' => $c->id]);
    }

    public function test_nao_aceita_comentario_de_outra_requisicao(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_ENCARREGADO]);
        $reqA = $this->requisicao($user);
        $reqB = $this->requisicao($user);
        $c    = $this->comentario($reqA, $user);

        // Comentário pertence à reqA, mas a rota aponta para reqB
        $this->actingAs($user)
            ->deleteJson(route('requisicoes.comentarios.destroy', [$reqB, $c]))
            ->assertNotFound();

        $this->assertDatabaseHas('comentarios', ['id' => $c->id]);
    }

    // ── Contador aparece na listagem ──────────────────────────────────────────

    public function test_listagem_traz_contador_de_comentarios(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req  = $this->requisicao($user);
        $this->comentario($req, $user, 'um');
        $this->comentario($req, $user, 'dois');

        $this->actingAs($user)
            ->get(route('requisicoes.index'))
            ->assertInertia(fn($page) => $page
                ->where('pendentes.0.comentarios_count', 2));
    }
}
