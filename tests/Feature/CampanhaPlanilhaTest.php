<?php

namespace Tests\Feature;

use App\Models\CampanhaFornecedor;
use App\Models\Configuracao;
use App\Models\Fornecedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * A planilha de compras que preenche o faturamento sozinho.
 *
 * O leitor precisa aguentar o que o ERP entrega de verdade: cabeçalho fora da
 * primeira linha, colunas em outra ordem, linha de total no fim e número
 * escrito como texto. Cada um desses tem um teste aqui.
 */
class CampanhaPlanilhaTest extends TestCase
{
    use RefreshDatabase;

    private User $compras;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compras = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        Configuracao::definirCampanhaAtiva(true);
    }

    // ── Importação ────────────────────────────────────────────────────────────

    public function test_importa_a_planilha_e_monta_a_base(): void
    {
        $arquivo = $this->planilha([
            ['Ranking', 'Fornecedor', 'Valor de Compra Total'],
            [1, 'NL DISTRIBUIDORA DE ALIMENTOS LTDA', 21119251.98],
            [2, 'VIVALOG', 2742889.78],
        ]);

        $this->actingAs($this->compras)
            ->post(route('campanha.planilha.importar'), ['planilha' => $arquivo])
            ->assertRedirect(route('campanha.index'));

        $this->assertSame(2, CampanhaFornecedor::count());
        $this->assertSame('2742889.78', CampanhaFornecedor::where('nome', 'VIVALOG')->value('faturamento'));
    }

    public function test_planilha_nova_substitui_a_antiga_inteira(): void
    {
        $this->importar([
            ['Fornecedor', 'Valor de Compra Total'],
            ['FORNECEDOR ANTIGO', 100.0],
        ]);

        $this->importar([
            ['Fornecedor', 'Valor de Compra Total'],
            ['FORNECEDOR NOVO', 200.0],
        ]);

        // Fotografia, não mistura: quem saiu do ranking não fica para trás.
        $this->assertSame(1, CampanhaFornecedor::count());
        $this->assertDatabaseMissing('campanha_fornecedores', ['nome' => 'FORNECEDOR ANTIGO']);
    }

    public function test_descarta_o_total_geral_mas_mantem_fornecedor_que_comeca_com_total(): void
    {
        $this->importar([
            ['Fornecedor', 'Valor de Compra Total'],
            ['TOTAL QUIMICA LIMITADA', 46713.76],
            ['ALGUM FORNECEDOR', 1000.0],
            ['TOTAL GERAL', 47713.76],
        ]);

        $this->assertDatabaseHas('campanha_fornecedores', ['nome' => 'TOTAL QUIMICA LIMITADA']);
        $this->assertDatabaseMissing('campanha_fornecedores', ['nome' => 'TOTAL GERAL']);
        $this->assertSame(2, CampanhaFornecedor::count());
    }

    public function test_acha_as_colunas_fora_de_ordem_e_com_cabecalho_abaixo(): void
    {
        $this->importar([
            ['Ranking de Compras — 12 meses', null, null],   // título antes do cabeçalho
            [null, null, null],
            ['Valor de Compra Total', 'Qtd', 'Fornecedor'],  // colunas trocadas
            [8512473.99, 2363940, 'SPAL IND BRAS'],
        ]);

        $this->assertDatabaseHas('campanha_fornecedores', ['nome' => 'SPAL IND BRAS']);
        $this->assertSame('8512473.99', CampanhaFornecedor::where('nome', 'SPAL IND BRAS')->value('faturamento'));
    }

    public function test_entende_numero_escrito_como_texto(): void
    {
        $this->importar([
            ['Fornecedor', 'Faturamento'],
            ['VILMA ALIMENTOS', 'R$ 1.234.567,89'],
        ]);

        $this->assertSame('1234567.89', CampanhaFornecedor::where('nome', 'VILMA ALIMENTOS')->value('faturamento'));
    }

    public function test_ignora_a_coluna_de_uma_tabela_so_quando_existe_o_total(): void
    {
        // A planilha real tem "Valor Tabela 1", "Valor Tabela 2" E o total —
        // pegar a coluna errada daria um faturamento pela metade na carta.
        $this->importar([
            ['Fornecedor', 'Valor Tabela 1', 'Valor Tabela 2', 'Valor de Compra Total'],
            ['BRF S.A', 4973998.82, 5257766.56, 10231765.38],
        ]);

        $this->assertSame('10231765.38', CampanhaFornecedor::where('nome', 'BRF S.A')->value('faturamento'));
    }

    public function test_rodape_de_observacao_nao_vira_fornecedor(): void
    {
        $this->importar([
            ['Fornecedor', 'Valor de Compra Total'],
            ['FORNECEDOR DE VERDADE', 500.0],
            ['Consolidação das planilhas enviadas pelo usuário.', null],
        ]);

        $this->assertSame(1, CampanhaFornecedor::count());
    }

    // ── Recusas ───────────────────────────────────────────────────────────────

    public function test_recusa_arquivo_que_nao_e_xlsx(): void
    {
        $this->actingAs($this->compras)
            ->post(route('campanha.planilha.importar'), [
                'planilha' => UploadedFile::fake()->createWithContent('ranking.csv', 'Fornecedor;Valor'),
            ])
            ->assertSessionHasErrors('planilha');

        $this->assertSame(0, CampanhaFornecedor::count());
    }

    public function test_recusa_planilha_sem_as_colunas(): void
    {
        $this->actingAs($this->compras)
            ->post(route('campanha.planilha.importar'), [
                'planilha' => $this->planilha([
                    ['Produto', 'Preço'],
                    ['ARROZ 5KG', 25.90],
                ]),
            ])
            ->assertSessionHasErrors('planilha');

        $this->assertSame(0, CampanhaFornecedor::count());
    }

    public function test_papel_sem_acesso_nao_importa(): void
    {
        $preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);

        $this->actingAs($preLote)
            ->post(route('campanha.planilha.importar'), [
                'planilha' => $this->planilha([['Fornecedor', 'Valor de Compra Total'], ['X', 1.0]]),
            ])
            ->assertForbidden();

        $this->assertSame(0, CampanhaFornecedor::count());
    }

    // ── A tela ────────────────────────────────────────────────────────────────

    public function test_a_tela_sugere_os_fornecedores_da_planilha_com_o_valor(): void
    {
        Fornecedor::create(['nome' => 'FORNECEDOR SO DAS NOTAS']);

        $this->importar([
            ['Fornecedor', 'Valor de Compra Total'],
            ['VIVALOG', 2742889.78],
        ]);

        $this->actingAs($this->compras)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page
                ->where('fornecedores', [['nome' => 'VIVALOG', 'faturamento' => 2742889.78]])
                ->where('base.linhas', 1)
                ->where('base.enviado_por', $this->compras->name)
                // 2.0 volta do JSON como inteiro — o que importa é o valor.
                ->where('percentualSugerido', 2)
            );
    }

    public function test_sem_planilha_a_tela_cai_no_cadastro_das_notas(): void
    {
        Fornecedor::create(['nome' => 'FORNECEDOR SO DAS NOTAS']);

        $this->actingAs($this->compras)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page
                ->where('fornecedores', [['nome' => 'FORNECEDOR SO DAS NOTAS', 'faturamento' => null]])
                ->where('base', null)
            );
    }

    public function test_remover_a_base_devolve_a_tela_ao_estado_anterior(): void
    {
        $this->importar([
            ['Fornecedor', 'Valor de Compra Total'],
            ['VIVALOG', 2742889.78],
        ]);

        $this->actingAs($this->compras)
            ->delete(route('campanha.planilha.remover'))
            ->assertRedirect(route('campanha.index'));

        $this->assertSame(0, CampanhaFornecedor::count());

        $this->actingAs($this->compras)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page->where('base', null));
    }

    // ── Ferramentas do teste ──────────────────────────────────────────────────

    /** @param  list<array<int, mixed>>  $linhas */
    private function importar(array $linhas): void
    {
        $this->actingAs($this->compras)
            ->post(route('campanha.planilha.importar'), ['planilha' => $this->planilha($linhas)])
            ->assertSessionHasNoErrors();
    }

    /**
     * Monta um .xlsx de verdade — ZIP com os mesmos XMLs que o Excel grava,
     * texto na tabela compartilhada e número solto na célula.
     *
     * Vale mais que um arquivo de exemplo guardado no repositório: aqui dá para
     * montar a planilha torta que o teste precisa, e a base real (com valores de
     * compra dos fornecedores) não vira histórico público no git.
     *
     * @param  list<array<int, mixed>>  $linhas
     */
    private function planilha(array $linhas, string $nome = 'Ranking_de_Compras_Total.xlsx'): UploadedFile
    {
        $textos = [];
        $sheet  = '';

        foreach ($linhas as $i => $linha) {
            $celulas = '';

            foreach (array_values($linha) as $coluna => $valor) {
                if ($valor === null || $valor === '') {
                    continue;
                }

                $referencia = chr(65 + $coluna) . ($i + 1);

                if (is_int($valor) || is_float($valor)) {
                    $celulas .= '<c r="' . $referencia . '"><v>' . $valor . '</v></c>';
                    continue;
                }

                $posicao = array_search((string) $valor, $textos, true);

                if ($posicao === false) {
                    $textos[] = (string) $valor;
                    $posicao = count($textos) - 1;
                }

                $celulas .= '<c r="' . $referencia . '" t="s"><v>' . $posicao . '</v></c>';
            }

            $sheet .= '<row r="' . ($i + 1) . '">' . $celulas . '</row>';
        }

        $compartilhados = '';
        foreach ($textos as $texto) {
            $compartilhados .= '<si><t xml:space="preserve">'
                . htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8')
                . '</t></si>';
        }

        $caminho = tempnam(sys_get_temp_dir(), 'planilha') . '.xlsx';

        $zip = new ZipArchive();
        $zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Total" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/sharedStrings.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($textos) . '" '
            . 'uniqueCount="' . count($textos) . '">' . $compartilhados . '</sst>');

        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheet . '</sheetData></worksheet>');

        $zip->close();

        return new UploadedFile($caminho, $nome, null, null, true);
    }
}
