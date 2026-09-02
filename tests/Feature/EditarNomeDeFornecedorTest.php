<?php

namespace Tests\Feature;

use App\Models\CampanhaFornecedor;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O admin corrige nomes de fornecedor — nas duas listas, que não se misturam.
 */
class EditarNomeDeFornecedorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * Renomear conserta o histórico inteiro.
     *
     * A nota aponta para o id, não para o texto — então a nota de três meses
     * atrás passa a mostrar o nome certo sem nada ser reescrito nela.
     */
    public function test_renomear_arruma_o_nome_em_todas_as_notas(): void
    {
        $forn = Fornecedor::create(['nome' => 'VILMA ALIMENTOSS']);
        $nota = Nota::create([
            'numero_nota' => '7', 'fornecedor_id' => $forn->id, 'user_id' => $this->admin->id,
            'loja' => 1, 'origem' => 'recebimento',
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('configuracoes.fornecedores.renomear', $forn), ['nome' => 'VILMA ALIMENTOS'])
            ->assertOk()
            ->assertJsonPath('fornecedor.nome', 'VILMA ALIMENTOS');

        $this->assertSame('VILMA ALIMENTOS', $nota->fresh()->fornecedor->nome);
    }

    /** Espaço sobrando e no meio some — é metade dos "nomes diferentes". */
    public function test_espacos_sao_normalizados(): void
    {
        $forn = Fornecedor::create(['nome' => 'X']);

        $this->actingAs($this->admin)
            ->patchJson(route('configuracoes.fornecedores.renomear', $forn), ['nome' => '  VILMA   ALIMENTOS  '])
            ->assertOk()
            ->assertJsonPath('fornecedor.nome', 'VILMA ALIMENTOS');
    }

    /**
     * Renomear para um nome que já existe é pedido de FUSÃO, e é recusado.
     *
     * A recusa vem com o número de notas dos dois lados: é a informação que
     * falta para decidir se vale juntar, e sem ela o admin só veria "não pode".
     */
    public function test_nome_repetido_e_recusado_com_o_tamanho_dos_dois_lados(): void
    {
        $certo  = Fornecedor::create(['nome' => 'VILMA ALIMENTOS']);
        $errado = Fornecedor::create(['nome' => 'VILMA ALIMENTOSS']);

        foreach (['1', '2', '3'] as $n) {
            Nota::create(['numero_nota' => $n, 'fornecedor_id' => $certo->id,
                'user_id' => $this->admin->id, 'loja' => 1, 'origem' => 'recebimento']);
        }
        Nota::create(['numero_nota' => '9', 'fornecedor_id' => $errado->id,
            'user_id' => $this->admin->id, 'loja' => 1, 'origem' => 'recebimento']);

        $r = $this->actingAs($this->admin)
            ->patchJson(route('configuracoes.fornecedores.renomear', $errado), ['nome' => 'VILMA ALIMENTOS'])
            ->assertStatus(422);

        $this->assertStringContainsString('3 notas', $r->json('erro'));
        $this->assertStringContainsString('Este tem 1', $r->json('erro'));

        // E nada mudou: a recusa não pode deixar meio-caminho
        $this->assertSame('VILMA ALIMENTOSS', $errado->fresh()->nome);
    }

    /**
     * Na campanha, a chave é recalculada junto.
     *
     * É ela que reconhece o fornecedor quando o comprador digita o nome em vez
     * de escolher na lista — um nome novo com a chave velha deixaria de casar
     * exatamente com o que se acabou de escrever.
     */
    public function test_renomear_na_campanha_recalcula_a_chave(): void
    {
        $f = CampanhaFornecedor::create([
            'nome' => 'Vilma Alimentoss', 'chave' => CampanhaFornecedor::chaveDe('Vilma Alimentoss'),
            'faturamento' => 100000,
        ]);

        $this->actingAs($this->admin)
            ->patchJson(route('configuracoes.fornecedores.campanha.renomear', $f), ['nome' => 'Vilma Alimentos'])
            ->assertOk();

        $f->refresh();

        $this->assertSame('Vilma Alimentos', $f->nome);
        $this->assertSame(CampanhaFornecedor::chaveDe('Vilma Alimentos'), $f->chave);
        $this->assertSame('VILMA ALIMENTOS', $f->chave);
    }

    public function test_busca_encontra_pelo_pedaco_do_nome(): void
    {
        Fornecedor::create(['nome' => 'VILMA ALIMENTOS']);
        Fornecedor::create(['nome' => 'OUTRO QUALQUER']);

        $this->actingAs($this->admin)
            ->getJson(route('configuracoes.fornecedores.buscar', ['q' => 'vilma', 'tipo' => 'notas']))
            ->assertOk()
            ->assertJsonCount(1, 'fornecedores')
            ->assertJsonPath('fornecedores.0.nome', 'VILMA ALIMENTOS');
    }

    /** As duas listas são separadas: buscar numa não traz a outra. */
    public function test_as_duas_listas_nao_se_misturam(): void
    {
        Fornecedor::create(['nome' => 'SO NAS NOTAS']);
        CampanhaFornecedor::create([
            'nome' => 'SO NA CAMPANHA', 'chave' => 'SO NA CAMPANHA', 'faturamento' => 1,
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('configuracoes.fornecedores.buscar', ['q' => 'SO', 'tipo' => 'notas']))
            ->assertJsonCount(1, 'fornecedores')
            ->assertJsonPath('fornecedores.0.nome', 'SO NAS NOTAS');

        $this->actingAs($this->admin)
            ->getJson(route('configuracoes.fornecedores.buscar', ['q' => 'SO', 'tipo' => 'campanha']))
            ->assertJsonCount(1, 'fornecedores')
            ->assertJsonPath('fornecedores.0.nome', 'SO NA CAMPANHA');
    }

    /** Configuração é do admin — os outros papéis nem alcançam a rota. */
    public function test_so_admin_edita(): void
    {
        $forn = Fornecedor::create(['nome' => 'X']);

        foreach ([User::ROLE_PRE_LOTE, User::ROLE_COMPRAS, User::ROLE_RECEBIMENTO] as $papel) {
            $this->actingAs(User::factory()->create(['role' => $papel]))
                ->patchJson(route('configuracoes.fornecedores.renomear', $forn), ['nome' => 'Y'])
                ->assertForbidden();
        }

        $this->assertSame('X', $forn->fresh()->nome);
    }
}
