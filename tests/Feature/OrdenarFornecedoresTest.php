<?php

namespace Tests\Feature;

use App\Models\CampanhaFornecedor;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Configurações › Fornecedores: ordenar clicando no nome da coluna.
 *
 * Diferente das outras tabelas, a ordem sai do servidor: a lista volta
 * cortada, e ordenar só o pedaço que veio mostraria errado quem tem mais
 * notas. O que se protege aqui é que a ordem valha ANTES do corte.
 */
class OrdenarFornecedoresTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function nomes(array $params): array
    {
        return collect(
            $this->actingAs($this->admin)
                ->getJson(route('configuracoes.fornecedores.buscar', $params))
                ->assertOk()
                ->json('fornecedores')
        )->pluck('nome')->all();
    }

    private function campanha(string $nome, float $faturamento): void
    {
        CampanhaFornecedor::create([
            'nome' => $nome, 'chave' => CampanhaFornecedor::chaveDe($nome), 'faturamento' => $faturamento,
        ]);
    }

    public function test_sem_ordem_continua_alfabetica(): void
    {
        foreach (['CARLOS', 'ANA', 'BETO'] as $n) Fornecedor::create(['nome' => $n]);

        $this->assertSame(['ANA', 'BETO', 'CARLOS'], $this->nomes(['tipo' => 'notas']));
        $this->assertSame(['CARLOS', 'BETO', 'ANA'], $this->nomes(['tipo' => 'notas', 'ordenar' => 'nome', 'dir' => 'desc']));
    }

    public function test_ordena_pelo_numero_de_notas_antes_de_cortar(): void
    {
        // 70 nomes que vêm antes em ordem alfabética, sem nota nenhuma — sem a
        // ordem no servidor, o ZÉ (que é quem tem notas) nem chegaria à tela.
        for ($i = 0; $i < 70; $i++) Fornecedor::create(['nome' => sprintf('A%03d', $i)]);

        $ze = Fornecedor::create(['nome' => 'ZÉ']);
        foreach ([1, 2] as $n) {
            Nota::create([
                'numero_nota' => (string) $n, 'fornecedor_id' => $ze->id, 'user_id' => $this->admin->id,
                'loja' => 1, 'origem' => 'recebimento',
            ]);
        }

        $this->assertSame('ZÉ', $this->nomes(['tipo' => 'notas', 'ordenar' => 'peso', 'dir' => 'desc'])[0]);
    }

    public function test_campanha_ordena_pelo_faturamento(): void
    {
        $this->campanha('ALTO', 900);
        $this->campanha('BAIXO', 100);
        $this->campanha('MEIO', 500);

        $this->assertSame(['ALTO', 'MEIO', 'BAIXO'], $this->nomes(['tipo' => 'campanha', 'ordenar' => 'peso', 'dir' => 'desc']));
        $this->assertSame(['BAIXO', 'MEIO', 'ALTO'], $this->nomes(['tipo' => 'campanha', 'ordenar' => 'peso', 'dir' => 'asc']));
    }

    public function test_ordem_invalida_e_recusada(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('configuracoes.fornecedores.buscar', ['tipo' => 'notas', 'ordenar' => 'id; drop table']))
            ->assertStatus(422);
    }
}
