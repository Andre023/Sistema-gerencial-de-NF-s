<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que a tela de notas devolve a cada ação — e o que ela NÃO devolve.
 *
 * Toda ação no Inertia é POST → 302 → GET desta página. Medido em produção, a
 * resposta tinha 202 KB, dos quais 136 KB (67%) eram os ~2.800 fornecedores —
 * a única prop que nunca muda — mais 88 ms de PHP montando modelos que a tela
 * jogava fora. Era o maior desperdício de cada clique na fila.
 *
 * Estes testes existem porque a regressão é silenciosa: tirar o `only:` de uma
 * ação, ou trocar o `Inertia::optional` por uma prop comum, volta a arrastar os
 * 136 KB sem quebrar nada visível.
 */
class PesoDaTelaDeNotasTest extends TestCase
{
    use RefreshDatabase;

    private User $preLote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);

        $forn = Fornecedor::firstOrCreate(['nome' => 'FORN']);
        Nota::create([
            'numero_nota'   => '4242',
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    /**
     * Cabeçalhos de um reload parcial, como o navegador manda.
     *
     * A versão precisa ser a de verdade: o Inertia responde 409 ("recarregue a
     * página") quando ela não bate, e o teste passaria a medir o 409 em vez do
     * que interessa.
     */
    private function parcial(string $props): array
    {
        return [
            'X-Inertia'                   => 'true',
            'X-Inertia-Partial-Data'      => $props,
            'X-Inertia-Partial-Component' => 'Notas/Index',
            // Calculada como o próprio middleware calcula (hash do manifest do
            // Vite). `Inertia::getVersion()` não serve aqui: ela só devolve o
            // valor DEPOIS que o middleware rodou, e nós estamos antes.
            'X-Inertia-Version' => (new \App\Http\Middleware\HandleInertiaRequests())
                ->version(request()),
        ];
    }

    /** A lista pesada fica de fora até alguém pedir. */
    public function test_fornecedores_nao_vem_no_carregamento_normal(): void
    {
        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->has('recebimento')          // a fila vem
                ->missing('fornecedores'));   // a lista de 136 KB, não
    }

    /**
     * E chega inteira quando o formulário a pede pelo nome.
     *
     * A resposta de um reload parcial é JSON puro (o objeto de página), e não o
     * HTML com a página embutida — por isso aqui é assertJson e não
     * assertInertia, que só sabe ler a casca HTML do primeiro carregamento.
     */
    public function test_fornecedores_chegam_quando_pedidos(): void
    {
        $this->actingAs($this->preLote)
            ->get(route('notas.index'), $this->parcial('fornecedores'))
            ->assertOk()
            ->assertJsonCount(1, 'props.fornecedores')
            ->assertJsonPath('props.fornecedores.0.nome', 'FORN');
    }

    /**
     * O flash tem de sobreviver ao reload parcial.
     *
     * As ações da fila pedem só as props que podem ter mudado, e num parcial o
     * Inertia devolve apenas o que foi pedido. Sem o `Inertia::always` no
     * middleware, o "Nota liberada." nunca chegaria na tela — e o aviso de quem
     * já está olhando a nota, que viaja em flash.erro, se perderia junto.
     */
    public function test_flash_sobrevive_ao_reload_parcial(): void
    {
        $resposta = $this->actingAs($this->preLote)
            ->withSession(['sucesso' => 'Nota liberada.'])
            ->get(route('notas.index'), $this->parcial('recebimento'))
            ->assertOk();

        $resposta->assertJsonPath('props.flash.sucesso', 'Nota liberada.');
        $resposta->assertJsonCount(1, 'props.recebimento');

        // E a prop pesada continua fora mesmo aqui
        $this->assertArrayNotHasKey('fornecedores', $resposta->json('props'));
    }

    /**
     * Uma ação da fila não pode arrastar os fornecedores de volta.
     *
     * É a regressão que este arquivo existe para pegar: basta alguém tirar o
     * `only:` de uma ação para os 136 KB voltarem a viajar a cada clique, sem
     * nada quebrar na tela.
     */
    public function test_acao_na_fila_nao_traz_a_lista_pesada(): void
    {
        $nota = Nota::firstOrFail();

        // O redirect de uma ação é seguido pelo navegador como reload parcial
        $this->actingAs($this->preLote)->post(route('notas.liberar', $nota));

        $props = $this->actingAs($this->preLote)
            ->get(route('notas.index'), $this->parcial('recebimento,preLote,liberadas'))
            ->assertOk()
            ->json('props');

        $this->assertArrayHasKey('liberadas', $props);
        $this->assertArrayNotHasKey('fornecedores', $props);
    }

    /**
     * O sino e o chat vêm na abertura da tela.
     *
     * São props compartilhadas: se sumissem, o sino abriria zerado e o rosto de
     * quem falou não apareceria na barra até a primeira mensagem nova chegar.
     */
    public function test_o_sino_e_o_chat_vem_no_carregamento_normal(): void
    {
        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->has('notificacoes')
                ->has('conversasPendentes'));
    }

    /**
     * E ficam de fora das ações — que é o ponto de serem closure.
     *
     * Como chamada direta no middleware elas rodavam em TODA requisição, mesmo
     * nas ações que descartam o resultado: 6,6 ms e 4 consultas do sino, mais
     * 1,7 ms e 2 do chat, por card confirmado (medido em produção).
     *
     * O Inertia só avalia closure quando a prop entra na resposta, então este
     * teste falha no instante em que alguém devolver a chamada direta.
     */
    public function test_sino_e_chat_ficam_de_fora_das_acoes(): void
    {
        $props = $this->actingAs($this->preLote)
            ->get(route('notas.index'), $this->parcial('recebimento,preLote,liberadas'))
            ->assertOk()
            ->json('props');

        $this->assertArrayNotHasKey('notificacoes', $props);
        $this->assertArrayNotHasKey('conversasPendentes', $props);
    }
}
