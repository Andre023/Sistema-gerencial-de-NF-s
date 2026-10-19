<?php

namespace Tests\Feature;

use App\Models\Cotacao;
use App\Models\CotacaoFolha;
use App\Models\User;
use App\Services\PlanilhaComEstilo;
use App\Support\LeitorDePlanilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A aba Cotação: do estoque baixo das lojas ao pedido.
 *
 * O caminho inteiro passa por aqui — as listas entram, a prévia junta os
 * produtos, as respostas trazem os preços e a planilha de compra escolhe o
 * menor. E o que mais importa ao comprador: gerar de novo não apaga o que ele
 * já escreveu.
 */
class CotacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $compras;
    private Cotacao $cotacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compras = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->cotacao = Cotacao::create(['titulo' => 'Cotação teste', 'criada_por' => $this->compras->id]);
    }

    // ── Acesso ────────────────────────────────────────────────────────────────

    public function test_so_compras_e_admin_abrem_a_cotacao(): void
    {
        $this->actingAs($this->compras)->get(route('cotacao.index'))->assertOk();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get(route('cotacao.index'))->assertOk();

        foreach ([User::ROLE_RECEBIMENTO, User::ROLE_PRE_LOTE, User::ROLE_VISITANTE] as $papel) {
            $this->actingAs(User::factory()->create(['role' => $papel]))
                ->get(route('cotacao.index'))->assertForbidden();
        }
    }

    public function test_folha_de_outra_cotacao_nao_e_alcancavel_pela_url(): void
    {
        $outra = Cotacao::create(['titulo' => 'Outra']);
        $folha = $outra->folhas()->create(['tipo' => 'lista', 'nome' => 'x', 'colunas' => [], 'linhas' => []]);

        $this->actingAs($this->compras)
            ->getJson(route('cotacao.folhas.mostrar', [$this->cotacao, $folha]))
            ->assertNotFound();
    }

    // ── Entrada ───────────────────────────────────────────────────────────────

    public function test_le_a_lista_de_compra_e_adivinha_loja_e_setor_pelo_titulo(): void
    {
        $this->importarLista('beb.xlsx', [
            ['Bebidas Hiper Gentil Bicalho'],
            ['Produto', 'Descrição', 'GTIN Principal', 'Última Entrada', 'Qtd. Últ. Ent.', 'Forn. Últ. Ent.', 'Custo', 'Venda'],
            [870542, 'AGUA COCO CAMPO LARGO 250ML', 7896931614941, 46281, 240, 'ESTHAMPA CEASA', 3.861753, 5.99],
        ])->assertOk()->assertJsonPath('erros', []);

        $folha = CotacaoFolha::firstOrFail();

        $this->assertSame(['loja' => 'gb', 'setor' => 'beb'], $folha->meta);
        $this->assertSame('7896931614941', $folha->linhas[0]['v']['gtin']);
        $this->assertSame('16/09/2026', $folha->linhas[0]['v']['data']);
        $this->assertSame(3.861753, $folha->linhas[0]['v']['custo']);
    }

    public function test_arquivo_ruim_volta_como_erro_sem_derrubar_os_bons(): void
    {
        $resposta = $this->actingAs($this->compras)->postJson(route('cotacao.listas.importar', $this->cotacao), [
            'arquivos' => [
                $this->arquivo('boa.xlsx', [['Descrição', 'GTIN'], ['ARROZ 5KG', '789100000001']]),
                $this->arquivo('sem colunas.xlsx', [['nada', 'aqui']]),
                UploadedFile::fake()->create('antiga.xls', 10),
            ],
        ]);

        $resposta->assertOk()->assertJsonCount(1, 'folhas')->assertJsonCount(2, 'erros');
    }

    public function test_le_o_nome_do_fornecedor_e_ignora_a_linha_do_setor(): void
    {
        $this->importarResposta('qualquer.xlsx', [
            ['Cotação de preços'],
            ['Fornecedor: ', 'UPSIDE CEASA'],
            ['GTIN Principal', 'Descrição', 'Valor'],
            [789100000001, 'ARROZ 5KG', 'R$ 21,50'],
            [],
            ['Bebidas'],
            [789100000002, 'SUCO 1L', ''],
        ]);

        $folha = CotacaoFolha::where('tipo', 'resposta')->firstOrFail();

        $this->assertSame('UPSIDE CEASA', $folha->meta['fornecedor']);
        $this->assertCount(2, $folha->linhas);
        $this->assertSame(21.5, $folha->linhas[0]['v']['valor']);
        $this->assertArrayNotHasKey('valor', $folha->linhas[1]['v']);
    }

    public function test_sem_nome_de_fornecedor_usa_o_nome_do_arquivo(): void
    {
        $this->importarResposta('ESTHAMPA.xlsx', [
            ['Fornecedor: ', '(O fornecedor vai inserir o nome aqui)'],
            ['GTIN Principal', 'Descrição', 'Valor'],
            [789100000001, 'ARROZ 5KG', 20],
        ]);

        $this->assertSame('ESTHAMPA', CotacaoFolha::where('tipo', 'resposta')->value('nome'));
    }

    // ── Prévia e compra ───────────────────────────────────────────────────────

    public function test_previa_junta_as_lojas_sem_repetir_e_separa_os_setores(): void
    {
        $this->listasDasDuasLojas();

        $folha = $this->actingAs($this->compras)->postJson(route('cotacao.previa.gerar', $this->cotacao))
            ->assertOk()->json('folha');

        $this->assertSame(
            [['Mercearia', 'ARROZ 5KG'], ['Mercearia', 'FEIJAO 1KG'], ['Bebidas', 'SUCO 1L']],
            array_map(fn($l) => [$l['v']['setor'], $l['v']['desc']], $folha['linhas']),
        );
    }

    public function test_planilha_de_compra_escolhe_o_menor_e_o_segundo_menor(): void
    {
        $this->listasDasDuasLojas();
        $this->respostas();

        $folhas = $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao))
            ->assertOk()->json('folhas');

        $this->assertSame(['Gentil Bicalho · Mercearia', 'Gentil Bicalho · Bebidas', 'Wilson Alvarenga · Mercearia'], array_column($folhas, 'nome'));

        $arroz = collect($folhas[0]['linhas'])->firstWhere('v.desc', 'ARROZ 5KG')['v'];
        $this->assertSame(['SOMAMIX', 19.9, 'UPSIDE', 21.5, 2], [$arroz['forn1'], $arroz['preco1'], $arroz['forn2'], $arroz['preco2'], $arroz['cotacoes']]);
        $this->assertSame('-1%', $arroz['vs']);

        // Fornecedor que apagou o GTIN: o preço é achado pela descrição.
        $feijao = collect($folhas[0]['linhas'])->firstWhere('v.desc', 'FEIJAO 1KG')['v'];
        $this->assertSame('UPSIDE', $feijao['forn1']);

        $suco = collect($folhas[1]['linhas'])->firstWhere('v.desc', 'SUCO 1L')['v'];
        $this->assertSame('sem cotação', $suco['forn1']);
    }

    public function test_gerar_de_novo_mantem_o_que_o_comprador_escreveu(): void
    {
        $this->listasDasDuasLojas();
        $this->respostas();
        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao))->assertOk();

        $folha = CotacaoFolha::where('tipo', 'compra')->orderBy('ordem')->firstOrFail();
        $arroz = collect($folha->linhas)->firstWhere('v.desc', 'ARROZ 5KG');

        $this->alterar($folha, [
            ['t' => 'cels', 'itens' => [[$arroz['id'], 'quantidade', 10], [$arroz['id'], 'fator', 12]]],
            ['t' => 'est', 'alvo' => 'lin', 'itens' => [['l' => $arroz['id'], 'e' => ['bg' => '#FFEB3B']]]],
            ['t' => 'ins', 'apos' => null, 'linhas' => [['id' => 'manual1', 'v' => ['desc' => 'PRODUTO NOVO']]]],
        ])->assertOk()->assertJsonPath('versao', 1);

        // Chega mais um fornecedor, com o arroz mais barato.
        $this->importarResposta('novo.xlsx', [
            ['Fornecedor:', 'BARATÃO'],
            ['GTIN Principal', 'Descrição', 'Valor'],
            [789100000001, 'ARROZ 5KG', 18],
        ]);
        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao))->assertOk();

        $folha->refresh();
        $arroz = collect($folha->linhas)->firstWhere('id', $arroz['id']);

        $this->assertEquals(['BARATÃO', 18, 10, 12], [$arroz['v']['forn1'], $arroz['v']['preco1'], $arroz['v']['quantidade'], $arroz['v']['fator']]);
        $this->assertSame(['bg' => '#ffeb3b'], $arroz['s']);
        $this->assertNotNull(collect($folha->linhas)->firstWhere('id', 'manual1'));
        $this->assertSame(2, $folha->versao);
    }

    public function test_compra_exige_a_loja_de_cada_lista(): void
    {
        $this->importarLista('sem loja.xlsx', [['Descrição', 'GTIN'], ['ARROZ 5KG', '789100000001']]);

        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao))
            ->assertStatus(422);
    }

    // ── Edição ────────────────────────────────────────────────────────────────

    public function test_operacoes_mexem_so_no_que_dizem(): void
    {
        $folha = $this->cotacao->folhas()->create([
            'tipo' => 'lista', 'nome' => 'L', 'colunas' => [['id' => 'desc', 'nome' => 'Descrição', 'tipo' => 'texto', 'largura' => 200]],
            'linhas' => [['id' => 'a', 'v' => ['desc' => 'A']], ['id' => 'b', 'v' => ['desc' => 'B']], ['id' => 'c', 'v' => ['desc' => 'C']]],
        ]);

        $this->alterar($folha, [
            ['t' => 'cels', 'itens' => [['b', 'desc', 'B2'], ['sumiu', 'desc', 'X']]],
            ['t' => 'del', 'ids' => ['a']],
            ['t' => 'ins', 'apos' => 'b', 'linhas' => [['id' => 'n', 'v' => ['desc' => 'N']]]],
            ['t' => 'ord', 'ids' => ['c', 'n']],
            ['t' => 'col', 'coluna' => ['id' => 'obs', 'nome' => 'Obs'], 'apos' => 'desc'],
            ['t' => 'meta', 'meta' => ['loja' => 'wa', 'qualquer' => 'x']],
        ])->assertOk();

        $folha->refresh();

        $this->assertSame(['c', 'n', 'b'], array_column($folha->linhas, 'id'));
        $this->assertSame('B2', $folha->linhas[2]['v']['desc']);
        $this->assertSame(['desc', 'obs'], array_column($folha->colunas, 'id'));
        $this->assertSame(['loja' => 'wa'], $folha->meta);
    }

    public function test_operacao_malformada_nao_aplica_nada(): void
    {
        $folha = $this->cotacao->folhas()->create([
            'tipo' => 'lista', 'nome' => 'L', 'colunas' => [], 'linhas' => [['id' => 'a', 'v' => ['desc' => 'A']]],
        ]);

        $this->alterar($folha, [
            ['t' => 'cels', 'itens' => [['a', 'desc', 'mudou']]],
            ['t' => 'cels', 'itens' => [['a', 'COLUNA RUIM', 'x']]],
        ])->assertStatus(422);

        $this->assertSame('A', $folha->fresh()->linhas[0]['v']['desc']);
        $this->assertSame(0, $folha->fresh()->versao);
    }

    // ── Saída ─────────────────────────────────────────────────────────────────

    public function test_lista_para_fornecedores_volta_no_formato_que_o_leitor_entende(): void
    {
        $this->listasDasDuasLojas();
        $this->actingAs($this->compras)->postJson(route('cotacao.previa.gerar', $this->cotacao));

        $resposta = $this->actingAs($this->compras)->get(route('cotacao.exportar.lista', $this->cotacao))->assertOk();
        $caminho = tempnam(sys_get_temp_dir(), 'cot');
        file_put_contents($caminho, $resposta->getContent());

        $linhas = LeitorDePlanilha::ler($caminho)[0]['linhas'];
        unlink($caminho);

        $this->assertSame('Cotação de preços', $linhas[0][0]);
        $this->assertSame(['GTIN Principal', 'Descrição', 'Valor'], array_values($linhas[2]));
        $this->assertContains('Bebidas', array_map(fn($l) => $l[0] ?? null, $linhas));
    }

    public function test_lista_para_fornecedores_sai_so_do_setor_pedido(): void
    {
        $this->listasDasDuasLojas();
        $this->actingAs($this->compras)->postJson(route('cotacao.previa.gerar', $this->cotacao));

        $ler = function (?string $setor) {
            $resposta = $this->actingAs($this->compras)
                ->get(route('cotacao.exportar.lista', array_filter(['cotacao' => $this->cotacao->id, 'setor' => $setor])))
                ->assertOk();
            $caminho = tempnam(sys_get_temp_dir(), 'cot');
            file_put_contents($caminho, $resposta->getContent());
            $linhas = LeitorDePlanilha::ler($caminho)[0]['linhas'];
            unlink($caminho);

            return [$linhas[0][0], array_values(array_filter(array_map(fn($l) => $l[1] ?? null, array_slice($linhas, 3))))];
        };

        $this->assertSame(['Cotação de preços — Bebidas', ['SUCO 1L']], $ler('beb'));
        $this->assertSame(['Cotação de preços — Mercearia', ['ARROZ 5KG', 'FEIJAO 1KG']], $ler('merc'));
        $this->assertSame(['Cotação de preços', ['ARROZ 5KG', 'FEIJAO 1KG', 'SUCO 1L']], $ler(null));

        $this->actingAs($this->compras)->get(route('cotacao.exportar.lista', ['cotacao' => $this->cotacao->id, 'setor' => 'xyz']))
            ->assertSessionHasErrors('setor');
    }

    public function test_planilha_de_compra_sai_com_as_abas_e_as_cores(): void
    {
        $this->listasDasDuasLojas();
        $this->respostas();
        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao));

        $folha = CotacaoFolha::where('tipo', 'compra')->orderBy('ordem')->firstOrFail();
        $this->alterar($folha, [['t' => 'est', 'alvo' => 'lin', 'itens' => [['l' => $folha->linhas[0]['id'], 'e' => ['bg' => '#c8e6c9']]]]]);
        // Fornecedor preenchido: a linha sai verde de comprada.
        $this->alterar($folha, [['t' => 'cels', 'itens' => [[$folha->linhas[count($folha->linhas) - 1]['id'], 'fornecedor', 'CEASA BOX 12']]]]);

        $resposta = $this->actingAs($this->compras)->get(route('cotacao.exportar.compra', $this->cotacao))->assertOk();
        $caminho = tempnam(sys_get_temp_dir(), 'cot');
        file_put_contents($caminho, $resposta->getContent());

        $zip = new \ZipArchive();
        $zip->open($caminho);
        $abas = array_column(LeitorDePlanilha::ler($caminho), 'nome');
        $estilos = $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($caminho);

        $this->assertSame(['GB Mercearia', 'GB Bebidas', 'WA Mercearia', 'Por fornecedor', 'Comparativo'], $abas);
        $this->assertStringContainsString('FFC8E6C9', $estilos);
        $this->assertStringContainsString('FFD3F5DC', $estilos);
    }

    // ── Colunas: ocultar, nomes e o modelo para as próximas ──────────────────

    public function test_planilha_de_compra_tem_preco_for_e_fornecedor_no_fim(): void
    {
        $this->listasDasDuasLojas();
        $folhas = $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao))->json('folhas');

        $nomes = array_column($folhas[0]['colunas'], 'nome');
        $this->assertSame(['Fator', 'Quantidade', 'Preço for', 'Fornecedor'], array_slice($nomes, -4));
    }

    public function test_folha_antiga_ganha_o_nome_novo_e_a_coluna_fornecedor_no_lugar(): void
    {
        $this->listasDasDuasLojas();
        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao));

        // Como ficava a folha gerada antes da mudança: "Preço fornecedor" e sem
        // a coluna Fornecedor — mais uma coluna que o comprador criou no fim.
        $folha = CotacaoFolha::where('tipo', 'compra')->orderBy('ordem')->firstOrFail();
        $colunas = array_values(array_filter($folha->colunas, fn($c) => $c['id'] !== 'fornecedor'));
        foreach ($colunas as &$c) {
            if ($c['id'] === 'preco_forn') $c['nome'] = 'Preço fornecedor';
        }
        $colunas[] = ['id' => 'obs', 'nome' => 'Obs', 'tipo' => 'texto', 'largura' => 100];
        $folha->update(['colunas' => $colunas]);

        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao));

        $this->assertSame(['Quantidade', 'Preço for', 'Fornecedor', 'Obs'], array_slice(array_column($folha->fresh()->colunas, 'nome'), -4));
    }

    public function test_coluna_oculta_sobrevive_ao_atualizar_e_sai_oculta_no_excel(): void
    {
        $this->listasDasDuasLojas();
        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao));
        $folha = CotacaoFolha::where('tipo', 'compra')->orderBy('ordem')->firstOrFail();

        $this->alterar($folha, [['t' => 'col', 'coluna' => ['id' => 'venda', 'oculta' => true]]])->assertOk();
        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao));

        $venda = collect($folha->fresh()->colunas)->firstWhere('id', 'venda');
        $this->assertTrue($venda['oculta']);

        $caminho = tempnam(sys_get_temp_dir(), 'cot');
        file_put_contents($caminho, $this->actingAs($this->compras)->get(route('cotacao.folhas.exportar', [$this->cotacao, $folha]))->getContent());
        $zip = new \ZipArchive();
        $zip->open($caminho);
        $aba = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($caminho);

        // Venda é a 5ª coluna (E).
        $this->assertMatchesRegularExpression('#<col min="5" max="5" [^>]*hidden="1"/>#', $aba);
        $this->assertSame(1, substr_count($aba, 'hidden="1"'));

        // Reexibir tira a marca da folha, em vez de guardar "oculta: false".
        $this->alterar($folha, [['t' => 'col', 'coluna' => ['id' => 'venda', 'oculta' => false]]]);
        $this->assertArrayNotHasKey('oculta', collect($folha->fresh()->colunas)->firstWhere('id', 'venda'));
    }

    public function test_cotacao_nova_nasce_com_as_colunas_como_os_compradores_deixaram(): void
    {
        $this->listasDasDuasLojas();
        $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao));
        $folha = CotacaoFolha::where('tipo', 'compra')->where('meta->setor', 'merc')->where('meta->loja', 'gb')->firstOrFail();
        $this->alterar($folha, [
            ['t' => 'col', 'coluna' => ['id' => 'venda', 'oculta' => true]],
            ['t' => 'col', 'coluna' => ['id' => 'fator', 'nome' => 'Fator cx', 'largura' => 55]],
        ]);
        $lista = CotacaoFolha::where('tipo', 'lista')->firstOrFail();
        $this->alterar($lista, [['t' => 'col', 'coluna' => ['id' => 'data', 'oculta' => true]]]);

        // A cotação da semana seguinte.
        $this->cotacao = Cotacao::create(['titulo' => 'Semana que vem']);
        $this->listasDasDuasLojas();
        $folhas = $this->actingAs($this->compras)->postJson(route('cotacao.compra.gerar', $this->cotacao))->json('folhas');

        $gbMerc = collect($folhas[0]['colunas'])->keyBy('id');
        $this->assertTrue($gbMerc['venda']['oculta']);
        $this->assertSame(['Fator cx', 55], [$gbMerc['fator']['nome'], $gbMerc['fator']['largura']]);
        $this->assertTrue(collect($this->cotacao->folhas()->where('tipo', 'lista')->first()->colunas)->firstWhere('id', 'data')['oculta']);
    }

    // ── Apoio ─────────────────────────────────────────────────────────────────

    private function listasDasDuasLojas(): void
    {
        $cabecalho = ['Produto', 'Descrição', 'GTIN Principal', 'Custo', 'Venda'];

        $this->importarLista('merc gb.xlsx', [['GENTIL BICALHO'], $cabecalho,
            [1, 'ARROZ 5KG', 789100000001, 20.1, 29.9], [2, 'FEIJAO 1KG', 789100000003, 7.5, 9.9]]);
        $this->importarLista('beb gb.xlsx', [['Bebidas Hiper Gentil Bicalho'], $cabecalho,
            [3, 'SUCO 1L', 789100000002, 4, 6.5]]);
        $this->importarLista('merc wa.xlsx', [['WILSON ALVARENGA'], $cabecalho,
            [1, 'ARROZ 5KG', '0789100000001', 20.3, 29.9]]);
    }

    private function respostas(): void
    {
        $this->importarResposta('upside.xlsx', [['Fornecedor:', 'UPSIDE'], ['GTIN Principal', 'Descrição', 'Valor'],
            [789100000001, 'ARROZ 5KG', 21.5], ['', 'FEIJAO 1KG', '7,10']]);
        $this->importarResposta('somamix.xlsx', [['Fornecedor:', 'SOMAMIX'], ['GTIN Principal', 'Descrição', 'Valor'],
            [789100000001, 'ARROZ 5KG', 19.9]]);
    }

    private function importarLista(string $nome, array $linhas)
    {
        return $this->actingAs($this->compras)->postJson(route('cotacao.listas.importar', $this->cotacao), [
            'arquivos' => [$this->arquivo($nome, $linhas)],
        ]);
    }

    private function importarResposta(string $nome, array $linhas)
    {
        return $this->actingAs($this->compras)->postJson(route('cotacao.respostas.importar', $this->cotacao), [
            'arquivos' => [$this->arquivo($nome, $linhas)],
        ])->assertOk();
    }

    private function alterar(CotacaoFolha $folha, array $ops)
    {
        return $this->actingAs($this->compras)->patchJson(route('cotacao.folhas.alterar', [$this->cotacao, $folha]), [
            'ops' => $ops, 'cliente' => 'teste',
        ]);
    }

    /** Um .xlsx de verdade, montado pelo mesmo escritor da exportação. */
    private function arquivo(string $nome, array $linhas): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cot');
        file_put_contents($caminho, (new PlanilhaComEstilo())->aba('Planilha1', [], $linhas)->bytes());

        return new UploadedFile($caminho, $nome, null, null, true);
    }
}
