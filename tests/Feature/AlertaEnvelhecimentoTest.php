<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlertaEnvelhecimentoTest extends TestCase
{
    use RefreshDatabase;

    private function notaCriadaHa(int $dias, User $dono): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'FORNECEDOR TESTE']);

        $nota = Nota::create([
            'numero_nota'   => (string) rand(1000, 99999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $dono->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);

        $nota->forceFill(['created_at' => now()->subDays($dias)->setTime(9, 0)])->saveQuietly();

        return $nota->fresh();
    }

    // ── Limiares: 0 normal | 1-2 atenção | 3-6 alerta | 7+ crítico ─────────────

    public static function limiares(): array
    {
        return [
            'hoje'     => [0, Nota::NIVEL_NORMAL],
            '1 dia'    => [1, Nota::NIVEL_ATENCAO],
            '3 dias'   => [3, Nota::NIVEL_ALERTA],
            '7 dias'   => [7, Nota::NIVEL_CRITICO],
            '90 dias'  => [90, Nota::NIVEL_CRITICO],
        ];
    }

    #[DataProvider('limiares')]
    public function test_nivel_por_idade(int $dias, string $esperado): void
    {
        $user = User::factory()->create();
        $nota = $this->notaCriadaHa($dias, $user);
        $hoje = now()->toDateString();

        $this->assertSame($dias, $nota->diasEmAberto($hoje));
        $this->assertSame($esperado, $nota->nivelAlerta($hoje));
    }

    public function test_dias_em_aberto_nunca_e_negativo(): void
    {
        $user = User::factory()->create();
        $nota = $this->notaCriadaHa(0, $user);

        $passado = now()->subDays(5)->toDateString();

        $this->assertSame(0, $nota->diasEmAberto($passado));
        $this->assertSame(Nota::NIVEL_NORMAL, $nota->nivelAlerta($passado));
    }

    // ── Contadores e filtro na fila ───────────────────────────────────────────

    public function test_resumo_conta_e_filtro_por_nivel_funciona(): void
    {
        $user = User::factory()->create();

        $this->notaCriadaHa(0, $user);   // normal
        $this->notaCriadaHa(2, $user);   // atenção
        $this->notaCriadaHa(4, $user);   // alerta
        $this->notaCriadaHa(30, $user);  // crítico

        $this->actingAs($user)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('resumoAlertas.atencao', 1)
                ->where('resumoAlertas.alerta', 1)
                ->where('resumoAlertas.critico', 1)
                ->has('recebimento', 4));

        // Filtro reduz a lista mas os contadores mantêm o panorama
        $this->actingAs($user)
            ->get(route('notas.index', ['nivel' => Nota::NIVEL_CRITICO]))
            ->assertInertia(fn($page) => $page
                ->has('recebimento', 1)
                ->where('recebimento.0.nivel', 'critico')
                ->where('resumoAlertas.atencao', 1));
    }
}
