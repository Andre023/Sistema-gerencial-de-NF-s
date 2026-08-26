<?php

namespace Tests\Feature;

use App\Models\CampanhaTexto;
use App\Models\Configuracao;
use App\Models\User;
use App\Support\CartaCampanha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * A aba Campanha: quem entra, o texto de cada comprador e o Word que sai.
 *
 * O ponto sensível é o interruptor — desligada, a aba não pode abrir nem para
 * quem sabe o endereço. É a única trava entre a tela e o resto do sistema.
 */
class CampanhaTest extends TestCase
{
    use RefreshDatabase;

    private User $compras;
    private User $admin;
    private User $preLote;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compras = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->admin   = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
    }

    private function ligarCampanha(): void
    {
        Configuracao::definirCampanhaAtiva(true);
    }

    // ── Interruptor ───────────────────────────────────────────────────────────

    public function test_desligada_a_aba_nao_abre_para_ninguem(): void
    {
        $this->actingAs($this->compras)->get(route('campanha.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('campanha.index'))->assertForbidden();

        // E nem por dentro: gerar o Word também está fechado.
        $this->actingAs($this->compras)
            ->post(route('campanha.baixar'), $this->dadosValidos())
            ->assertForbidden();
    }

    public function test_nasce_desligada(): void
    {
        $this->assertFalse(Configuracao::campanhaAtiva());
    }

    public function test_admin_liga_e_compras_entra(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('configuracoes.campanha.atualizar'), [
                'ativa'        => true,
                'texto_padrao' => CartaCampanha::TEXTO_DE_FABRICA,
            ])
            ->assertRedirect();

        $this->assertTrue(Configuracao::campanhaAtiva());
        $this->actingAs($this->compras)->get(route('campanha.index'))->assertOk();
    }

    public function test_ligada_continua_fechada_para_os_outros_papeis(): void
    {
        $this->ligarCampanha();

        $this->actingAs($this->preLote)->get(route('campanha.index'))->assertForbidden();

        $recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->actingAs($recebimento)->get(route('campanha.index'))->assertForbidden();

        $visitante = User::factory()->create(['role' => User::ROLE_VISITANTE]);
        $this->actingAs($visitante)->get(route('campanha.index'))->assertForbidden();

        // Compras e admin, sim.
        $this->actingAs($this->compras)->get(route('campanha.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('campanha.index'))->assertOk();
    }

    public function test_configuracoes_da_campanha_e_so_do_admin(): void
    {
        $this->actingAs($this->compras)->get(route('configuracoes.campanha'))->assertForbidden();
        $this->actingAs($this->preLote)->get(route('configuracoes.campanha'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('configuracoes.campanha'))->assertOk();

        $this->actingAs($this->compras)
            ->patch(route('configuracoes.campanha.atualizar'), ['ativa' => true, 'texto_padrao' => 'x'])
            ->assertForbidden();

        $this->assertFalse(Configuracao::campanhaAtiva());
    }

    // ── O texto de cada comprador ─────────────────────────────────────────────

    public function test_sem_perfil_a_tela_abre_com_o_texto_padrao(): void
    {
        $this->ligarCampanha();

        $this->actingAs($this->compras)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page
                ->component('Campanha/Index')
                ->where('texto', CartaCampanha::TEXTO_DE_FABRICA)
                ->where('temPerfil', false)
            );
    }

    public function test_perfil_salvo_e_de_quem_salvou(): void
    {
        $this->ligarCampanha();
        $outroComprador = User::factory()->create(['role' => User::ROLE_COMPRAS]);

        $this->actingAs($this->compras)
            ->post(route('campanha.texto.salvar'), ['texto' => 'Olá, (nome do fornecedor)!'])
            ->assertRedirect();

        $this->actingAs($this->compras)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page->where('texto', 'Olá, (nome do fornecedor)!')->where('temPerfil', true));

        // O colega continua no padrão — o texto é de cada um.
        $this->actingAs($outroComprador)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page->where('texto', CartaCampanha::TEXTO_DE_FABRICA)->where('temPerfil', false));
    }

    public function test_salvar_de_novo_atualiza_o_mesmo_perfil(): void
    {
        $this->ligarCampanha();

        $this->actingAs($this->compras)->post(route('campanha.texto.salvar'), ['texto' => 'primeiro']);
        $this->actingAs($this->compras)->post(route('campanha.texto.salvar'), ['texto' => 'segundo']);

        $this->assertSame(1, CampanhaTexto::where('user_id', $this->compras->id)->count());
        $this->assertSame('segundo', CampanhaTexto::where('user_id', $this->compras->id)->value('texto'));
    }

    public function test_restaurar_apaga_o_perfil_e_devolve_o_padrao_atual(): void
    {
        $this->ligarCampanha();

        $this->actingAs($this->compras)->post(route('campanha.texto.salvar'), ['texto' => 'meu texto']);

        // O admin muda o padrão da loja depois disso.
        Configuracao::definir(Configuracao::CAMPANHA_TEXTO_PADRAO, 'padrão novo da loja');

        $this->actingAs($this->compras)->delete(route('campanha.texto.restaurar'))->assertRedirect();

        $this->assertDatabaseMissing('campanha_textos', ['user_id' => $this->compras->id]);

        // Quem restaurou passa a ver o padrão NOVO, não uma cópia do antigo.
        $this->actingAs($this->compras)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page->where('texto', 'padrão novo da loja'));
    }

    public function test_admin_troca_o_texto_padrao(): void
    {
        $this->actingAs($this->admin)
            ->patch(route('configuracoes.campanha.atualizar'), [
                'ativa'        => true,
                'texto_padrao' => 'Carta de 2027 com (nome do fornecedor).',
            ])
            ->assertRedirect();

        $this->actingAs($this->compras)
            ->get(route('campanha.index'))
            ->assertInertia(fn($page) => $page->where('texto', 'Carta de 2027 com (nome do fornecedor).'));
    }

    // ── O Word ────────────────────────────────────────────────────────────────

    public function test_baixar_devolve_um_docx_com_os_dados_no_lugar(): void
    {
        $this->ligarCampanha();

        $resposta = $this->actingAs($this->compras)
            ->post(route('campanha.baixar'), $this->dadosValidos());

        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $conteudo = (string) $resposta->getContent();

        // Assinatura de ZIP — é o que todo .docx é por dentro.
        $this->assertStringStartsWith('PK', $conteudo);

        $xml = $this->documentoXmlDe($conteudo);

        $this->assertStringContainsString('VIVALOG DISTRIBUICAO E LOGISTICA LT', $xml);
        $this->assertStringContainsString('R$ 2.536.257,21', $xml);
        $this->assertStringContainsString('R$ 20.000,00', $xml);

        // O nome e os valores saem em negrito: é o que o parceiro procura.
        $this->assertStringContainsString('<w:b/>', $xml);
    }

    public function test_r_cifrao_escrito_na_mao_nao_vira_r_cifrao_dobrado(): void
    {
        $this->ligarCampanha();

        $resposta = $this->actingAs($this->compras)->post(route('campanha.baixar'), [
            ...$this->dadosValidos(),
            'texto' => 'Faturamento de R$ (faturamento).',
        ]);

        $xml = $this->documentoXmlDe((string) $resposta->getContent());

        $this->assertStringContainsString('R$ 2.536.257,21', $xml);
        $this->assertStringNotContainsString('R$ R$', strip_tags($xml));
    }

    public function test_campos_obrigatorios(): void
    {
        $this->ligarCampanha();

        $this->actingAs($this->compras)
            ->post(route('campanha.baixar'), [...$this->dadosValidos(), 'fornecedor' => ''])
            ->assertSessionHasErrors('fornecedor');

        $this->actingAs($this->compras)
            ->post(route('campanha.baixar'), [...$this->dadosValidos(), 'faturamento' => 'muito'])
            ->assertSessionHasErrors('faturamento');
    }

    /** @return array<string, mixed> */
    private function dadosValidos(): array
    {
        return [
            'texto'        => CartaCampanha::TEXTO_DE_FABRICA,
            'fornecedor'   => 'VIVALOG DISTRIBUICAO E LOGISTICA LT',
            'faturamento'  => 2536257.21,
            'investimento' => 20000,
        ];
    }

    /** Abre o .docx que veio na resposta e devolve o XML do documento. */
    private function documentoXmlDe(string $conteudo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'teste-docx');
        file_put_contents($caminho, $conteudo);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($caminho) === true, 'O arquivo gerado não abriu como .docx.');

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($caminho);

        $this->assertIsString($xml);

        return $xml;
    }
}
