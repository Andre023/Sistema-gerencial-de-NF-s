<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A faxina do sino.
 *
 * O que estes testes protegem não é o número apagado — é o que NÃO pode ser
 * apagado. Um aviso pendente é uma cobrança em aberto, e a faxina levá-lo por
 * idade seria resolver a pendência escondendo-a.
 */
class LimparNotificacoesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Nota $nota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->nota = Nota::create([
            'numero_nota'   => '4001',
            'fornecedor_id' => Fornecedor::create(['nome' => 'TESTE'])->id,
            'user_id'       => $this->user->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ]);
    }

    private function aviso(array $extra = []): Notificacao
    {
        return Notificacao::create(array_merge([
            'user_id' => $this->user->id,
            'nota_id' => $this->nota->id,
            'tipo'    => Notificacao::TIPO_DIVERGENCIA,
            'dados'   => ['tipos' => ['custo']],
        ], $extra));
    }

    /** Envelhece o aviso: `created_at` é preenchido pelo Eloquent na criação. */
    private function envelhecer(Notificacao $n, int $dias): Notificacao
    {
        $n->forceFill(['created_at' => now()->subDays($dias)])->saveQuietly();

        return $n;
    }

    public function test_apaga_o_que_ja_foi_lido_e_esta_velho(): void
    {
        $velho = $this->envelhecer($this->aviso(['lida_em' => now()->subDays(70)]), 70);

        $this->artisan('notificacoes:limpar')->assertSuccessful();

        $this->assertDatabaseMissing('notificacoes', ['id' => $velho->id]);
    }

    public function test_apaga_o_encerrado_e_velho(): void
    {
        $velho = $this->envelhecer($this->aviso(['encerrada_em' => now()->subDays(70)]), 70);

        $this->artisan('notificacoes:limpar')->assertSuccessful();

        $this->assertDatabaseMissing('notificacoes', ['id' => $velho->id]);
    }

    /**
     * O caso que mais importa: pendente NÃO sai, por mais velho que seja.
     *
     * Se ninguém leu e ninguém encerrou, aquele aviso continua sendo a cobrança
     * de uma nota que segue parada. Apagá-lo por idade tiraria da tela a única
     * coisa que ainda apontava para o problema.
     */
    public function test_pendente_velho_permanece(): void
    {
        $pendente = $this->envelhecer($this->aviso(), 400);

        $this->artisan('notificacoes:limpar')->assertSuccessful();

        $this->assertDatabaseHas('notificacoes', ['id' => $pendente->id]);
    }

    /** Dentro do prazo fica, mesmo já resolvido — a faxina é por idade. */
    public function test_recente_permanece_mesmo_lido(): void
    {
        $recente = $this->aviso(['lida_em' => now()]);

        $this->artisan('notificacoes:limpar')->assertSuccessful();

        $this->assertDatabaseHas('notificacoes', ['id' => $recente->id]);
    }

    public function test_simular_nao_apaga_nada(): void
    {
        $velho = $this->envelhecer($this->aviso(['encerrada_em' => now()]), 70);

        $this->artisan('notificacoes:limpar --simular')->assertSuccessful();

        $this->assertDatabaseHas('notificacoes', ['id' => $velho->id]);
    }

    /**
     * `--dias=0` leva tudo o que já foi resolvido — e nada do que está pendente.
     *
     * O zero é o caso que a checagem `?? ` protege: com `?:`, "0" seria falso
     * em PHP e cairia calado no prazo padrão de 60 dias, sem apagar nada e sem
     * ninguém entender por quê.
     */
    public function test_dias_zero_leva_os_resolvidos_e_poupa_os_pendentes(): void
    {
        $resolvido = $this->aviso(['encerrada_em' => now()]);
        $pendente  = $this->aviso();

        $this->artisan('notificacoes:limpar --dias=0')->assertSuccessful();

        $this->assertDatabaseMissing('notificacoes', ['id' => $resolvido->id]);
        $this->assertDatabaseHas('notificacoes', ['id' => $pendente->id]);
    }

    /** O histórico da nota vive noutra tabela e não é tocado por esta faxina. */
    public function test_as_ocorrencias_da_nota_nao_sao_tocadas(): void
    {
        $this->envelhecer($this->aviso(['encerrada_em' => now()]), 70);

        $antes = \App\Models\Ocorrencia::where('nota_id', $this->nota->id)->count();

        $this->artisan('notificacoes:limpar')->assertSuccessful();

        $this->assertSame($antes, \App\Models\Ocorrencia::where('nota_id', $this->nota->id)->count());
        $this->assertGreaterThan(0, $antes, 'a nota criada no setUp já deixa ocorrência');
    }
}
