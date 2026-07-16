<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Requisicao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertaEnvelhecimentoTest extends TestCase
{
    use RefreshDatabase;

    private function requisicaoCriadaHa(int $dias, User $dono): Requisicao
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORNECEDOR TESTE']);

        $req = Requisicao::create([
            'numero_nota'   => (string) rand(1000, 9999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $dono->id,
            'loja'          => 1,
            'motivo'        => 'Preço',
            'status'        => 'Pendente',
        ]);

        // created_at é gerenciado pelo Eloquent — força a idade desejada
        $req->forceFill(['created_at' => now()->subDays($dias)->setTime(9, 0)])->saveQuietly();

        return $req->fresh();
    }

    // ── Limiares: 0 normal | 1-2 atenção | 3-6 alerta | 7+ crítico ─────────────

    public static function limiares(): array
    {
        return [
            'hoje'        => [0,  Requisicao::NIVEL_NORMAL],
            '1 dia'       => [1,  Requisicao::NIVEL_ATENCAO],
            '2 dias'      => [2,  Requisicao::NIVEL_ATENCAO],
            '3 dias'      => [3,  Requisicao::NIVEL_ALERTA],
            '6 dias'      => [6,  Requisicao::NIVEL_ALERTA],
            '7 dias'      => [7,  Requisicao::NIVEL_CRITICO],
            '181 dias'    => [181, Requisicao::NIVEL_CRITICO],
        ];
    }

    #[DataProvider('limiares')]
    public function test_nivel_por_idade(int $dias, string $esperado): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req  = $this->requisicaoCriadaHa($dias, $user);
        $hoje = now()->toDateString();

        $this->assertSame($dias, $req->diasEmAberto($hoje), "dias em aberto de {$dias}d");
        $this->assertSame($esperado, $req->nivelAlerta($hoje), "nível de {$dias}d");
    }

    public function test_dias_em_aberto_nunca_e_negativo(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $req  = $this->requisicaoCriadaHa(0, $user);

        // Data de filtro no passado (requisição "do futuro" em relação a ela)
        $passado = now()->subDays(5)->toDateString();

        $this->assertSame(0, $req->diasEmAberto($passado));
        $this->assertSame(Requisicao::NIVEL_NORMAL, $req->nivelAlerta($passado));
    }

    // ── Contadores do banner ──────────────────────────────────────────────────

    public function test_resumo_conta_por_severidade(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERADOR]);

        $this->requisicaoCriadaHa(0, $user);   // normal
        $this->requisicaoCriadaHa(1, $user);   // atenção
        $this->requisicaoCriadaHa(2, $user);   // atenção
        $this->requisicaoCriadaHa(4, $user);   // alerta
        $this->requisicaoCriadaHa(30, $user);  // crítico

        $this->actingAs($user)
            ->get(route('requisicoes.index'))
            ->assertInertia(fn($page) => $page
                ->where('resumoAlertas.atencao', 2)
                ->where('resumoAlertas.alerta', 1)
                ->where('resumoAlertas.critico', 1)
                ->has('pendentes', 5)); // sem filtro, vêm todas
    }

    // ── Filtro por nível ──────────────────────────────────────────────────────

    public function test_filtro_por_nivel_reduz_a_lista_mas_nao_os_contadores(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERADOR]);

        $this->requisicaoCriadaHa(1, $user);
        $this->requisicaoCriadaHa(10, $user);
        $this->requisicaoCriadaHa(20, $user);

        $this->actingAs($user)
            ->get(route('requisicoes.index', ['nivel' => Requisicao::NIVEL_CRITICO]))
            ->assertInertia(fn($page) => $page
                ->has('pendentes', 2)                       // só as críticas
                ->where('pendentes.0.nivel', 'critico')
                ->where('pendentes.1.nivel', 'critico')
                ->where('resumoAlertas.atencao', 1)         // contador segue mostrando o todo
                ->where('resumoAlertas.critico', 2)
                ->where('filtros.nivel', 'critico'));
    }

    public function test_nivel_invalido_e_ignorado(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $this->requisicaoCriadaHa(1, $user);

        $this->actingAs($user)
            ->get(route('requisicoes.index', ['nivel' => 'xpto']))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->where('filtros.nivel', null)
                ->has('pendentes', 1));
    }

    // ── Idade é relativa à data consultada, como o "arrastando" ────────────────

    public function test_idade_e_relativa_a_data_filtrada(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_OPERADOR]);
        $this->requisicaoCriadaHa(10, $user);

        // Consultando 8 dias atrás, a requisição tinha só 2 dias => atenção
        $this->actingAs($user)
            ->get(route('requisicoes.index', ['data' => now()->subDays(8)->toDateString()]))
            ->assertInertia(fn($page) => $page
                ->where('pendentes.0.dias_aberta', 2)
                ->where('pendentes.0.nivel', 'atencao'));
    }
}
