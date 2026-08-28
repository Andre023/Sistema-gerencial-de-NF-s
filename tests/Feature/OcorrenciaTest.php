<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Comentario;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Ocorrencia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O livro de ocorrências da nota.
 *
 * Os testes que mais importam aqui são os das ações que APAGAM a própria prova —
 * comentário excluído, card excluído, descancelar, devolver. Era exatamente o
 * que a linha do tempo deduzida não tinha como mostrar, e é por isso que a
 * tabela passou a existir.
 */
class OcorrenciaTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $preLote;
    private User $compras;
    private User $admin;
    private User $visitante;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->admin       = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->visitante   = User::factory()->create(['role' => User::ROLE_VISITANTE]);
    }

    private function nota(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORN']);

        return Nota::create(array_merge([
            'numero_nota'   => (string) random_int(1000, 9999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    /** @return array<int,string> as ações registradas na nota, da mais antiga à mais nova */
    private function acoes(Nota $nota): array
    {
        return Ocorrencia::where('nota_id', $nota->id)
            ->orderBy('id')->pluck('acao')->all();
    }

    private function ultima(Nota $nota, string $acao): Ocorrencia
    {
        $o = Ocorrencia::where('nota_id', $nota->id)->where('acao', $acao)->latest('id')->first();

        $this->assertNotNull($o, "Esperava uma ocorrência '{$acao}' e não houve nenhuma.");

        return $o;
    }

    // ─── O que a dedução não conseguia mostrar ───────────────────────────────

    /**
     * Comentário apagado some do banco (não há SoftDeletes) e podia ser apagado
     * por qualquer conta que gerencie notas. Sem o texto aqui, trocaríamos um
     * buraco por outro: saber que algo foi apagado sem saber o quê.
     */
    public function test_comentario_apagado_guarda_o_texto_e_quem_apagou(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->compras)
            ->postJson(route('notas.comentarios.store', $nota), ['texto' => 'o fornecedor prometeu trocar'])
            ->assertCreated();

        $comentario = Comentario::firstOrFail();

        // Quem apaga NÃO é quem escreveu — é o caso que mais pede registro
        $this->actingAs($this->admin)
            ->deleteJson(route('notas.comentarios.destroy', [$nota, $comentario]))
            ->assertOk();

        $this->assertDatabaseCount('comentarios', 0); // sumiu de vez do banco

        $o = $this->ultima($nota, Ocorrencia::COMENTARIO_EXCLUIDO);

        $this->assertSame('o fornecedor prometeu trocar', $o->dados['texto']);
        $this->assertSame($this->compras->name, $o->dados['autor']);   // quem escreveu
        $this->assertSame($this->admin->id, $o->user_id);              // quem apagou
    }

    /** Excluir o card levava junto "abriu", "corrigiu" e "resolveu" da linha do tempo. */
    public function test_card_excluido_fica_registrado_com_o_tipo(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'custo', 'detalhe' => 'preço divergente'])
            ->assertRedirect();

        $card = Card::firstOrFail();

        $this->actingAs($this->preLote)
            ->delete(route('notas.cards.destroy', [$nota, $card]))
            ->assertRedirect();

        $this->assertDatabaseCount('cards', 0);

        $o = $this->ultima($nota, Ocorrencia::CARD_EXCLUIDO);

        $this->assertSame('custo', $o->dados['tipo']);
        $this->assertSame('preço divergente', $o->dados['detalhe']);
        $this->assertContains(Ocorrencia::CARD_ABERTO, $this->acoes($nota));
    }

    /** Descancelar zera cancelada_em: o cancelamento e o motivo sumiam. */
    public function test_cancelar_e_descancelar_deixam_os_dois_registros(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.cancelar', $nota), ['motivo' => 'fornecedor cancelou a NF']);

        $this->actingAs($this->preLote)->post(route('notas.descancelar', $nota));

        $this->assertNull($nota->fresh()->cancelada_em); // o dado voltou ao que era

        $cancelou = $this->ultima($nota, Ocorrencia::NOTA_CANCELADA);
        $this->assertSame('fornecedor cancelou a NF', $cancelou->dados['contexto']['motivo']);

        $this->ultima($nota, Ocorrencia::NOTA_DESCANCELADA); // e a volta também ficou
    }

    /** Devolver zera liberada_em: o "fulano liberou" sumia da história. */
    public function test_liberar_e_devolver_deixam_os_dois_registros(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)->post(route('notas.liberar', $nota));
        $this->actingAs($this->preLote)->post(route('notas.devolver', $nota));

        $this->assertNull($nota->fresh()->liberada_em);

        $this->assertContains(Ocorrencia::NOTA_LIBERADA, $this->acoes($nota));
        $this->assertContains(Ocorrencia::NOTA_DEVOLVIDA, $this->acoes($nota));
    }

    /** Edição de campo: só o valor final sobrevivia na nota. */
    public function test_editar_observacao_guarda_o_antes_e_o_depois(): void
    {
        $nota = $this->nota(['observacao' => 'faltou 1 caixa']);

        $this->actingAs($this->compras)
            ->patch(route('notas.editar-liberada', $nota), ['observacao' => 'conferido, veio completo']);

        $o = $this->ultima($nota, Ocorrencia::NOTA_EDITADA);

        $this->assertSame('faltou 1 caixa', $o->dados['campos']['observacao']['de']);
        $this->assertSame('conferido, veio completo', $o->dados['campos']['observacao']['para']);
    }

    // ─── O que NÃO pode virar ocorrência ─────────────────────────────────────

    /**
     * O 🙋‍♂️ ("estou olhando esta nota") muda a cada clique e é estado de
     * presença, não mudança na nota. Sem esta trava ele afogaria o log.
     */
    public function test_reservar_a_nota_nao_gera_ocorrencia(): void
    {
        $nota = $this->nota();
        $antes = count($this->acoes($nota));

        $this->actingAs($this->preLote)->post(route('notas.visualizar', $nota)); // reserva
        $this->actingAs($this->preLote)->post(route('notas.visualizar', $nota)); // solta

        $this->assertCount($antes, $this->acoes($nota), 'A reserva não é mudança na nota.');
    }

    /**
     * Ação barrada não pode deixar intenção órfã.
     *
     * A intenção é declarada antes do update que a realiza. Se ela fosse
     * declarada antes das travas, uma liberação recusada deixaria "vou liberar"
     * pendurado — e a PRÓXIMA gravação da nota, que é de outra coisa qualquer,
     * sairia registrada como liberação. O log passaria a mentir, que é pior do
     * que não existir.
     */
    public function test_acao_barrada_nao_carimba_a_proxima(): void
    {
        $nota = $this->nota();

        // Card em aberto: a liberação é recusada
        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'custo']);
        $this->actingAs($this->preLote)->post(route('notas.liberar', $nota));

        $this->assertNull($nota->fresh()->liberada_em, 'A liberação tinha de ser recusada.');

        // Agora uma edição comum, que não tem nada a ver com liberar
        $this->actingAs($this->compras)
            ->patch(route('notas.editar-liberada', $nota), ['observacao' => 'liguei pro fornecedor']);

        $acoes = $this->acoes($nota);

        $this->assertNotContains(Ocorrencia::NOTA_LIBERADA, $acoes);
        $this->assertContains(Ocorrencia::NOTA_EDITADA, $acoes);
    }

    // ─── A tela ──────────────────────────────────────────────────────────────

    public function test_papel_operacional_le_as_ocorrencias(): void
    {
        $nota = $this->nota();

        foreach ([$this->recebimento, $this->preLote, $this->compras] as $quem) {
            $this->actingAs($quem)
                ->getJson(route('notas.ocorrencias.index', $nota))
                ->assertOk()
                ->assertJsonStructure(['ocorrencias' => [['id', 'acao', 'usuario', 'em']], 'campos']);
        }
    }

    public function test_visitante_nao_le_as_ocorrencias(): void
    {
        $this->actingAs($this->visitante)
            ->getJson(route('notas.ocorrencias.index', $this->nota()))
            ->assertForbidden();
    }

    /**
     * A nota LIBERADA também tem ocorrências — e é a que mais precisa delas.
     *
     * Depois de fechada ela continua sendo mexida: observação editada, devolvida
     * ao recebimento, às vezes excluída. É justamente aí que alguém pergunta
     * quem mexeu, e por isso o histórico não pode parar na liberação.
     *
     * O teste existe para segurar uma restrição por status que alguém possa
     * achar natural acrescentar depois ("log é da fila").
     */
    public function test_nota_liberada_continua_com_ocorrencias(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)->post(route('notas.liberar', $nota));
        $this->assertNotNull($nota->fresh()->liberada_em, 'a nota precisa estar liberada para este teste valer');

        // Mexer na nota JÁ liberada tem de continuar entrando no livro
        $this->actingAs($this->recebimento)
            ->patch(route('notas.editar-liberada', $nota), ['observacao' => 'faltou 1 caixa']);

        $acoes = collect(
            $this->actingAs($this->preLote)
                ->getJson(route('notas.ocorrencias.index', $nota))
                ->assertOk()
                ->json('ocorrencias')
        )->pluck('acao');

        $this->assertContains(Ocorrencia::NOTA_LIBERADA, $acoes);
        $this->assertContains(Ocorrencia::NOTA_EDITADA, $acoes, 'edição depois de liberada tem de ficar registrada');
    }

    /** O log é só de leitura: não existe rota para escrever nem para apagar. */
    public function test_nao_existe_rota_para_escrever_ou_apagar(): void
    {
        $rotas = collect(app('router')->getRoutes())
            ->filter(fn($r) => str_contains($r->uri(), 'ocorrencias'));

        $this->assertNotEmpty($rotas, 'A rota de leitura tem de existir.');

        foreach ($rotas as $rota) {
            $this->assertSame(
                ['GET', 'HEAD'],
                array_values(array_diff($rota->methods(), ['OPTIONS'])),
                "A rota {$rota->uri()} aceita escrita — o log deixaria de ser registro.",
            );
        }
    }

    /** A ordem é do mais novo para o mais antigo: quem abre quer o que acabou de acontecer. */
    public function test_a_lista_vem_do_mais_novo_para_o_mais_antigo(): void
    {
        $nota = $this->nota();
        $this->actingAs($this->preLote)->post(route('notas.liberar', $nota));

        $lista = $this->actingAs($this->preLote)
            ->getJson(route('notas.ocorrencias.index', $nota))
            ->json('ocorrencias');

        $this->assertSame(Ocorrencia::NOTA_LIBERADA, $lista[0]['acao']);
        $this->assertSame(Ocorrencia::NOTA_LANCADA, end($lista)['acao']);
    }
}
