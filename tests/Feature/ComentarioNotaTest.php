<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Comentario;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComentarioNotaTest extends TestCase
{
    use RefreshDatabase;

    private function nota(User $dono): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORNECEDOR TESTE']);

        return Nota::create([
            'numero_nota'   => (string) rand(1000, 99999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $dono->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    public function test_todos_os_papeis_podem_comentar(): void
    {
        $recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $nota        = $this->nota($recebimento);

        foreach ([$recebimento, $compras] as $u) {
            $this->actingAs($u)
                ->postJson(route('notas.comentarios.store', $nota), ['texto' => "Contexto de {$u->role}"])
                ->assertCreated();
        }

        $this->assertSame(2, $nota->comentarios()->count());
    }

    public function test_timeline_conta_a_historia_completa(): void
    {
        $recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO, 'name' => 'Rita Receb']);
        $preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE, 'name' => 'Pedro Prelote']);
        $compras     = User::factory()->create(['role' => User::ROLE_COMPRAS, 'name' => 'Carla Compras']);

        $nota = $this->nota($recebimento);
        $card = $nota->cards()->create([
            'tipo' => 'cadastro', 'status' => Card::STATUS_RESOLVIDO,
            'aberto_por' => $preLote->id,
            'corrigido_por' => $compras->id, 'corrigido_em' => now()->addMinutes(10),
            'resolvido_por' => $preLote->id, 'resolvido_em' => now()->addMinutes(20),
        ]);
        $nota->update(['liberada_por' => $preLote->id, 'liberada_em' => now()->addMinutes(30)]);
        $nota->comentarios()->create(['user_id' => $compras->id, 'texto' => 'Corrigido no ERP, chamado 123']);

        $timeline = $this->actingAs($recebimento)
            ->getJson(route('notas.comentarios.index', $nota))
            ->assertOk()
            ->json('timeline');

        $acoes = collect($timeline)->where('tipo', 'evento')->pluck('acao')->all();

        $this->assertContains('lançou a nota', $acoes);
        $this->assertContains('abriu divergência de cadastro', $acoes);
        $this->assertContains('marcou cadastro como corrigido', $acoes);
        $this->assertContains('resolveu cadastro', $acoes);
        $this->assertContains('liberou a nota', $acoes);
        $this->assertContains('Corrigido no ERP, chamado 123', collect($timeline)->where('tipo', 'comentario')->pluck('texto')->all());
    }

    public function test_autor_exclui_o_proprio_comentario_e_outros_nao(): void
    {
        $autor = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $outro = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);

        $nota = $this->nota($autor);

        $c1 = $nota->comentarios()->create(['user_id' => $autor->id, 'texto' => 'um']);
        $c2 = $nota->comentarios()->create(['user_id' => $autor->id, 'texto' => 'dois']);

        // Outro papel sem gestão não exclui comentário alheio
        $this->actingAs($outro)
            ->deleteJson(route('notas.comentarios.destroy', [$nota, $c1]))
            ->assertForbidden();

        // O autor exclui o próprio
        $this->actingAs($autor)
            ->deleteJson(route('notas.comentarios.destroy', [$nota, $c1]))
            ->assertOk();

        // Pré-lote (gestão) exclui de qualquer um
        $this->actingAs($preLote)
            ->deleteJson(route('notas.comentarios.destroy', [$nota, $c2]))
            ->assertOk();

        $this->assertSame(0, Comentario::count());
    }
}
