<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os saltos do aviso, seguindo o ciclo do card:
 *
 *   pré-lote abre  → compras
 *   compras corrige→ quem abriu o card
 *   pré-lote reabre→ compras de novo
 *   pré-lote libera→ quem lançou a nota
 */
class NotificacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $preLote;
    private User $compras;
    private User $compras2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->preLote     = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
        $this->compras2    = User::factory()->create(['role' => User::ROLE_COMPRAS]);
    }

    private function nota(array $extra = []): Nota
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);

        return Nota::create(array_merge([
            'numero_nota'   => '5342',
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->recebimento->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra));
    }

    private function abreCard(Nota $nota, string $tipo): void
    {
        $this->actingAs($this->preLote)
            ->post(route('notas.cards.store', $nota), ['tipo' => $tipo])
            ->assertRedirect();
    }

    private function pendentesDe(User $user)
    {
        return Notificacao::where('user_id', $user->id)->pendentes()->get();
    }

    // ── SALTO 1: divergência → compras ────────────────────────────────────────

    public function test_card_aberto_avisa_todo_o_compras(): void
    {
        $nota = $this->nota();

        $this->abreCard($nota, 'custo');

        foreach ([$this->compras, $this->compras2] as $comprador) {
            $aviso = $this->pendentesDe($comprador)->first();

            $this->assertNotNull($aviso, 'compras deveria ter sido avisado');
            $this->assertSame(Notificacao::TIPO_DIVERGENCIA, $aviso->tipo);
            $this->assertSame($nota->id, $aviso->nota_id);
            $this->assertSame(['custo'], $aviso->dados['tipos']);
        }
    }

    public function test_quem_abriu_o_card_nao_recebe_o_proprio_aviso(): void
    {
        $this->abreCard($this->nota(), 'custo');

        $this->assertCount(0, $this->pendentesDe($this->preLote));
    }

    public function test_dois_cards_acumulam_em_um_unico_aviso(): void
    {
        $nota = $this->nota();

        $this->abreCard($nota, 'custo');
        $this->abreCard($nota, 'cadastro');

        $avisos = $this->pendentesDe($this->compras);

        $this->assertCount(1, $avisos, 'deveria ser uma notificação por nota, não uma por card');
        $this->assertSame(['cadastro', 'custo'], $avisos->first()->dados['tipos']);
    }

    public function test_card_de_regra_nao_avisa_compras(): void
    {
        $this->abreCard($this->nota(), 'regra');

        $this->assertCount(0, $this->pendentesDe($this->compras));
    }

    public function test_card_sem_pedido_avisa_compras(): void
    {
        $nota = $this->nota();

        $this->abreCard($nota, 'sem_pedido');

        $aviso = $this->pendentesDe($this->compras)->first();

        $this->assertNotNull($aviso, 'sem pedido é de compras — deveria avisar');
        $this->assertSame(Notificacao::TIPO_DIVERGENCIA, $aviso->tipo);
        $this->assertSame(['sem_pedido'], $aviso->dados['tipos']);
    }

    public function test_aviso_lido_volta_a_pesar_quando_entra_divergencia_nova(): void
    {
        $nota = $this->nota();

        $this->abreCard($nota, 'custo');
        Notificacao::where('user_id', $this->compras->id)->update(['lida_em' => now()]);
        $this->assertCount(0, $this->pendentesDe($this->compras));

        $this->abreCard($nota, 'cadastro');

        $this->assertCount(1, $this->pendentesDe($this->compras));
    }

    // ── SALTO 2: corrigido → quem abriu o card ────────────────────────────────

    public function test_correcao_avisa_quem_abriu_o_card(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $card = $nota->cards()->first();

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]))
            ->assertRedirect();

        $aviso = $this->pendentesDe($this->preLote)->first();

        $this->assertNotNull($aviso, 'quem abriu o card deveria ser avisado da correção');
        $this->assertSame(Notificacao::TIPO_CORRIGIDO, $aviso->tipo);
        $this->assertSame(['custo'], $aviso->dados['tipos']);
    }

    public function test_correcao_encerra_o_aviso_dos_outros_compradores(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $card = $nota->cards()->first();

        $this->assertCount(1, $this->pendentesDe($this->compras2));

        $this->actingAs($this->compras)
            ->patch(route('notas.cards.corrigir', [$nota, $card]));

        $this->assertCount(0, $this->pendentesDe($this->compras2),
            'um comprador corrigiu — o aviso tem que sumir para os outros');
    }

    public function test_aviso_de_compras_so_encerra_quando_o_ultimo_card_deles_e_corrigido(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $this->abreCard($nota, 'cadastro');

        $custo = $nota->cards()->where('tipo', 'custo')->first();

        $this->actingAs($this->compras)->patch(route('notas.cards.corrigir', [$nota, $custo]));

        $aviso = $this->pendentesDe($this->compras2)->first();

        $this->assertNotNull($aviso, 'ainda falta o cadastro');
        $this->assertSame(['cadastro'], $aviso->fresh()->dados['tipos']);
    }

    // ── SALTO 1b: reabertura → compras de novo ────────────────────────────────

    public function test_reabertura_avisa_compras_e_encerra_o_de_reconferir(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $card = $nota->cards()->first();

        $this->actingAs($this->compras)->patch(route('notas.cards.corrigir', [$nota, $card]));
        $this->assertCount(1, $this->pendentesDe($this->preLote));

        $this->actingAs($this->preLote)
            ->patch(route('notas.cards.reabrir', [$nota, $card]))
            ->assertRedirect();

        $aviso = $this->pendentesDe($this->compras)->first();

        $this->assertNotNull($aviso, 'compras precisa saber que continua errado');
        $this->assertSame(Notificacao::TIPO_REABERTO, $aviso->tipo);

        $this->assertCount(0, $this->pendentesDe($this->preLote),
            'a reconferência aconteceu — o aviso de "corrigido" cumpriu seu papel');
    }

    // ── SALTO 3: liberada → quem lançou ───────────────────────────────────────

    public function test_liberacao_avisa_quem_lancou_a_nota(): void
    {
        $nota = $this->nota();

        $this->actingAs($this->preLote)
            ->post(route('notas.liberar', $nota))
            ->assertRedirect();

        $aviso = $this->pendentesDe($this->recebimento)->first();

        $this->assertNotNull($aviso);
        $this->assertSame(Notificacao::TIPO_LIBERADA, $aviso->tipo);
        $this->assertSame('5342', $aviso->nota->numero_nota);
    }

    public function test_nota_antecipada_liberada_pelo_proprio_pre_lote_nao_gera_aviso(): void
    {
        $nota = $this->nota(['origem' => 'pre_lote', 'user_id' => $this->preLote->id]);

        $this->actingAs($this->preLote)->post(route('notas.liberar', $nota));

        $this->assertCount(0, $this->pendentesDe($this->preLote),
            'quem lançou é quem liberou — não faz sentido se autoavisar');
    }

    public function test_liberacao_encerra_o_que_sobrou_da_nota(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $card = $nota->cards()->first();

        $this->actingAs($this->compras)->patch(route('notas.cards.corrigir', [$nota, $card]));
        $this->assertCount(1, $this->pendentesDe($this->preLote));

        $this->actingAs($this->preLote)->post(route('notas.liberar', $nota));

        $this->assertCount(0, $this->pendentesDe($this->preLote),
            'nota liberada é fim de linha: nada nela pede ação');
    }

    // ── Pré-lote resolve direto / apaga card ──────────────────────────────────

    public function test_pre_lote_resolvendo_direto_encerra_o_aviso_de_compras(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $card = $nota->cards()->first();

        $this->actingAs($this->preLote)->patch(route('notas.cards.resolver', [$nota, $card]));

        $this->assertCount(0, $this->pendentesDe($this->compras));
    }

    public function test_card_apagado_por_engano_encerra_o_aviso(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $card = $nota->cards()->first();

        $this->actingAs($this->preLote)->delete(route('notas.cards.destroy', [$nota, $card]));

        $this->assertCount(0, $this->pendentesDe($this->compras));
    }

    public function test_nota_excluida_encerra_os_avisos(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');

        $this->actingAs($this->preLote)->delete(route('notas.destroy', $nota));

        $this->assertCount(0, $this->pendentesDe($this->compras));
    }

    // ── Liga/desliga no perfil ────────────────────────────────────────────────

    public function test_quem_desligou_no_perfil_nao_recebe_aviso_novo(): void
    {
        $this->compras->update(['notificacoes_ativas' => false]);

        $this->abreCard($this->nota(), 'custo');

        $this->assertCount(0, $this->pendentesDe($this->compras));
        $this->assertCount(1, $this->pendentesDe($this->compras2), 'o outro comprador continua recebendo');
    }

    public function test_perfil_alterna_notificacoes(): void
    {
        $this->actingAs($this->compras)
            ->patch(route('profile.notificacoes'), ['notificacoes_ativas' => false])
            ->assertRedirect(route('profile.edit'));

        $this->assertFalse($this->compras->fresh()->notificacoes_ativas);

        $this->actingAs($this->compras)
            ->patch(route('profile.notificacoes'), ['notificacoes_ativas' => true]);

        $this->assertTrue($this->compras->fresh()->notificacoes_ativas);
    }

    // ── O sino ────────────────────────────────────────────────────────────────

    public function test_abrir_marca_como_lida_e_cai_na_nota(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $aviso = $this->pendentesDe($this->compras)->first();

        $this->actingAs($this->compras)
            ->post(route('notificacoes.abrir', $aviso))
            ->assertRedirect(route('notas.index', ['busca' => '5342', 'data' => now()->toDateString()]));

        $this->assertNotNull($aviso->fresh()->lida_em);
    }

    public function test_nao_abre_notificacao_de_outra_pessoa(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');
        $aviso = $this->pendentesDe($this->compras)->first();

        $this->actingAs($this->compras2)
            ->post(route('notificacoes.abrir', $aviso))
            ->assertNotFound();
    }

    public function test_ler_todas_zera_o_contador(): void
    {
        $nota = $this->nota();
        $this->abreCard($nota, 'custo');

        $this->actingAs($this->compras)
            ->post(route('notificacoes.lerTodas'))
            ->assertRedirect();

        $this->assertCount(0, $this->pendentesDe($this->compras));
        $this->assertCount(1, $this->pendentesDe($this->compras2), 'ler as minhas não mexe nas dos outros');
    }
}
