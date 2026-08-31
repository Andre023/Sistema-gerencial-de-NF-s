<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use App\Services\Notificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O sino mostra os últimos DIAS_NO_SINO dias, e só.
 *
 * O que estes testes protegem é a diferença entre SUMIR DA TELA e SER
 * RESOLVIDO. O aviso velho sai do sino, mas continua pendente no banco e a nota
 * continua na fila — quem cobra o que está parado é a fila, com as cores do
 * envelhecimento. O sino cobra o que é novo.
 */
class JanelaDoSinoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Nota $nota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->nota = Nota::create([
            'numero_nota'   => '7',
            'fornecedor_id' => Fornecedor::create(['nome' => 'TESTE'])->id,
            'user_id'       => $this->user->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    private function aviso(int $diasAtras = 0): Notificacao
    {
        $n = Notificacao::create([
            'user_id' => $this->user->id,
            'nota_id' => $this->nota->id,
            'tipo'    => Notificacao::TIPO_DIVERGENCIA,
            'dados'   => ['tipos' => ['custo']],
        ]);

        if ($diasAtras > 0) {
            $quando = now()->subDays($diasAtras);
            $n->forceFill(['created_at' => $quando, 'updated_at' => $quando])->saveQuietly();
        }

        return $n->fresh();
    }

    public function test_aviso_recente_aparece(): void
    {
        $this->aviso();

        $sino = Notificador::paraUsuario($this->user);

        $this->assertSame(1, $sino['pendentes']);
        $this->assertCount(1, $sino['itens']);
    }

    /** Passados os 3 dias, some da lista E do contador. */
    public function test_aviso_velho_sai_do_sino(): void
    {
        $this->aviso(diasAtras: Notificacao::DIAS_NO_SINO + 1);

        $sino = Notificador::paraUsuario($this->user);

        $this->assertSame(0, $sino['pendentes']);
        $this->assertCount(0, $sino['itens']);
    }

    /**
     * Sair do sino não é ser resolvido.
     *
     * A linha continua no banco e continua PENDENTE — se um dia alguém quiser
     * saber o que ficou sem resposta, está lá. O que mudou é só quem cobra:
     * passa a ser a fila, onde a nota envelhece com cor própria.
     */
    public function test_sumir_do_sino_nao_resolve_o_aviso(): void
    {
        $velho = $this->aviso(diasAtras: 10);

        Notificador::paraUsuario($this->user);

        $this->assertDatabaseHas('notificacoes', ['id' => $velho->id]);
        $this->assertNull($velho->fresh()->lida_em);
        $this->assertNull($velho->fresh()->encerrada_em);
    }

    /**
     * Novidade na MESMA nota traz o aviso de volta.
     *
     * Existe uma notificação viva por nota: quando chega outra divergência, a
     * linha é reescrita em vez de nascer uma segunda. Por isso a janela conta do
     * `updated_at` — contando da criação, a divergência de hoje herdaria a idade
     * da primeira e nasceria fora do sino, que é o pior erro possível aqui.
     */
    public function test_novidade_na_mesma_nota_traz_o_aviso_de_volta(): void
    {
        $velho = $this->aviso(diasAtras: 10);

        $this->assertCount(0, Notificador::paraUsuario($this->user)['itens'], 'começa fora do sino');

        // O motor reescreve a linha existente; aqui basta o carimbo de agora
        $velho->forceFill(['updated_at' => now()])->saveQuietly();

        $sino = Notificador::paraUsuario($this->user);

        $this->assertSame(1, $sino['pendentes']);
        $this->assertCount(1, $sino['itens']);
    }

    /** O contador e a lista têm de contar a mesma coisa. */
    public function test_contador_e_lista_usam_a_mesma_janela(): void
    {
        $this->aviso();
        $this->aviso(diasAtras: 5);
        $this->aviso(diasAtras: 30);

        $sino = Notificador::paraUsuario($this->user);

        $this->assertSame(1, $sino['pendentes'], 'só o recente conta');
        $this->assertCount(1, $sino['itens'], 'e é o mesmo que a lista mostra');
    }
}
