<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As demais ações da nota, e os modais, devolvendo só a linha alterada.
 *
 * O irmão AcaoDeCardEmJsonTest cobre os cards. Aqui ficam liberar, cancelar,
 * descancelar, devolver, visualizar, excluir, a observação, e os contadores de
 * comentário e anexo — que mudam a nota sem ser uma "ação" dela.
 */
class AcaoDeNotaEmJsonTest extends TestCase
{
    use RefreshDatabase;

    private User $preLote;
    private User $recebimento;
    private Nota $nota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);

        $this->nota = Nota::create([
            'numero_nota'   => '7',
            'fornecedor_id' => Fornecedor::create(['nome' => 'VERDE CAMPO'])->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    public function test_liberar_devolve_a_nota_liberada(): void
    {
        $this->actingAs($this->preLote)
            ->postJson(route('notas.liberar', $this->nota))
            ->assertOk()
            ->assertJsonPath('nota.id', $this->nota->id)
            ->assertJsonPath('nota.status', 'liberada')
            ->assertJsonPath('sucesso', 'Nota liberada.');
    }

    public function test_cancelar_e_descancelar_devolvem_o_estado_novo(): void
    {
        $this->actingAs($this->preLote)
            ->postJson(route('notas.cancelar', $this->nota), ['motivo' => 'fornecedor cancelou'])
            ->assertOk()
            ->assertJsonPath('nota.status', 'cancelada')
            ->assertJsonPath('nota.motivo_cancelamento', 'fornecedor cancelou');

        $this->actingAs($this->preLote)
            ->postJson(route('notas.descancelar', $this->nota))
            ->assertOk()
            ->assertJsonPath('nota.status', 'pendente')
            ->assertJsonPath('nota.cancelada_em', null);
    }

    public function test_devolver_traz_a_nota_de_volta_para_a_fila(): void
    {
        $this->actingAs($this->preLote)->postJson(route('notas.liberar', $this->nota));

        $this->actingAs($this->preLote)
            ->postJson(route('notas.devolver', $this->nota))
            ->assertOk()
            ->assertJsonPath('nota.liberada_em', null)
            ->assertJsonPath('nota.origem', 'recebimento');
    }

    /**
     * Excluir devolve o ID, não a nota — não há mais nota para desenhar.
     *
     * É o mesmo formato que o evento de tempo real usa para dizer "tire esta
     * linha", então a tela trata os dois sem saber a origem.
     */
    public function test_excluir_devolve_o_id_removido(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->deleteJson(route('notas.destroy', $this->nota))
            ->assertOk()
            ->assertJsonPath('removida', $this->nota->id)
            ->assertJsonMissingPath('nota');
    }

    /** A reserva 🙋‍♂️ devolve a linha com o dono. */
    public function test_visualizar_devolve_a_nota_reservada(): void
    {
        $this->actingAs($this->preLote)
            ->postJson(route('notas.visualizar', $this->nota))
            ->assertOk()
            ->assertJsonPath('nota.visualizando_por.id', $this->preLote->id);
    }

    /**
     * Nota de outra pessoa: 200 com recado, não erro.
     *
     * A ação fez exatamente o previsto — não tomou a reserva alheia e avisou de
     * quem é. Recusar com 422 diria que algo quebrou, e nada quebrou.
     */
    public function test_reserva_de_outro_volta_200_com_recado(): void
    {
        $this->actingAs($this->preLote)->postJson(route('notas.visualizar', $this->nota));

        $r = $this->actingAs($this->recebimento)
            ->postJson(route('notas.visualizar', $this->nota))
            ->assertOk();

        $this->assertStringContainsString('está olhando esta nota', $r->json('erro'));
        // A reserva continua sendo de quem chegou primeiro
        $r->assertJsonPath('nota.visualizando_por.id', $this->preLote->id);
    }

    public function test_editar_observacao_devolve_a_nota_com_o_texto_novo(): void
    {
        $this->actingAs($this->recebimento)
            ->patchJson(route('notas.editar-liberada', $this->nota), ['observacao' => 'faltou 1 caixa'])
            ->assertOk()
            ->assertJsonPath('nota.observacao', 'faltou 1 caixa')
            ->assertJsonPath('sucesso', 'Nota atualizada.');
    }

    /**
     * Comentar muda o CONTADOR da nota, e a fila precisa dele.
     *
     * Antes a tela recarregava as listas inteiras por causa de um número.
     */
    public function test_comentar_devolve_a_nota_com_o_contador(): void
    {
        $this->actingAs($this->preLote)
            ->postJson(route('notas.comentarios.store', $this->nota), ['texto' => 'liguei pro fornecedor'])
            ->assertCreated()
            ->assertJsonPath('nota.comentarios_count', 1)
            ->assertJsonStructure(['comentarios', 'nota']);
    }

    /** O caminho antigo continua para todas elas — é o que roda com filtro ativo. */
    public function test_sem_pedir_json_continua_redirecionando(): void
    {
        $this->actingAs($this->preLote)
            ->post(route('notas.liberar', $this->nota))
            ->assertRedirect()
            ->assertSessionHas('sucesso', 'Nota liberada.');
    }

    /** Regra recusada vira 422 com a mensagem pronta, como nos cards. */
    public function test_liberar_com_card_aberto_volta_422(): void
    {
        $this->nota->cards()->create([
            'tipo' => 'custo', 'status' => 'aberto', 'aberto_por' => $this->preLote->id,
        ]);

        $this->actingAs($this->preLote)
            ->postJson(route('notas.liberar', $this->nota))
            ->assertStatus(422)
            ->assertJsonPath('erro', 'A nota ainda tem divergência em aberto — resolva os cards antes de liberar.');
    }
}
