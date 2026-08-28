<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Ocorrencia;
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

    /**
     * A thread devolve conversa, e nada mais.
     *
     * Ela já misturava eventos deduzidos ("abriu cadastro", "corrigiu cadastro")
     * com o que as pessoas escreviam. O histórico saiu daqui e virou o livro de
     * ocorrências — que, por registrar na hora da ação, enxerga também o que foi
     * editado e apagado, coisa que a dedução nunca teve como ver.
     */
    public function test_a_thread_traz_so_comentarios(): void
    {
        $recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);

        $nota = $this->nota($recebimento);
        $nota->cards()->create([
            'tipo' => 'cadastro', 'status' => Card::STATUS_ABERTO, 'aberto_por' => $preLote->id,
        ]);
        $nota->comentarios()->create(['user_id' => $compras->id, 'texto' => 'Corrigido no ERP, chamado 123']);

        $corpo = $this->actingAs($recebimento)
            ->getJson(route('notas.comentarios.index', $nota))
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('timeline', $corpo, 'A thread não é mais linha do tempo.');
        $this->assertCount(1, $corpo['comentarios']);
        $this->assertSame('Corrigido no ERP, chamado 123', $corpo['comentarios'][0]['texto']);
    }

    /** E o que saiu da thread continua existindo — no lugar novo. */
    public function test_a_historia_dos_cards_agora_vive_nas_ocorrencias(): void
    {
        $recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);

        $nota = $this->nota($recebimento);

        $this->actingAs($preLote)->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro']);

        // Resolver antes de liberar não é detalhe do teste: card em aberto
        // impede a liberação (Nota::podeSerLiberada).
        $card = $nota->cards()->firstOrFail();
        $this->actingAs($preLote)->patch(route('notas.cards.resolver', [$nota, $card]));
        $this->actingAs($preLote)->post(route('notas.liberar', $nota));

        $acoes = collect(
            $this->actingAs($recebimento)
                ->getJson(route('notas.ocorrencias.index', $nota))
                ->assertOk()
                ->json('ocorrencias')
        )->pluck('acao')->all();

        $this->assertContains(Ocorrencia::NOTA_LANCADA, $acoes);
        $this->assertContains(Ocorrencia::CARD_ABERTO, $acoes);
        $this->assertContains(Ocorrencia::CARD_RESOLVIDO, $acoes);
        $this->assertContains(Ocorrencia::NOTA_LIBERADA, $acoes);
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
