<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Salto 0: o recebimento lança uma nota → o pré-lote é avisado ("Nota recém
 * lançada — analisar"). O aviso morre quando a nota deixa de esperar análise.
 */
class NotaLancadaAvisoTest extends TestCase
{
    use RefreshDatabase;

    private User $recebimento;
    private User $preLoteA;
    private User $preLoteB;
    private User $compras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recebimento = User::factory()->create(['role' => User::ROLE_RECEBIMENTO]);
        $this->preLoteA    = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->preLoteB    = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
        $this->compras     = User::factory()->create(['role' => User::ROLE_COMPRAS]);
    }

    private function lancar(User $quem, array $extra = []): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);

        $this->actingAs($quem)->post(route('notas.store'), array_merge([
            'numero_nota'   => (string) random_int(1000, 9999),
            'fornecedor_id' => $forn->id,
            'loja'          => 1,
            'origem'        => 'recebimento',
        ], $extra))->assertRedirect();
    }

    private function avisosLancada(User $de)
    {
        return Notificacao::where('user_id', $de->id)
            ->where('tipo', Notificacao::TIPO_LANCADA)
            ->viva()->get();
    }

    public function test_recebimento_lanca_e_todo_o_pre_lote_e_avisado(): void
    {
        $this->lancar($this->recebimento, ['numero_nota' => '5001']);

        $this->assertCount(1, $this->avisosLancada($this->preLoteA));
        $this->assertCount(1, $this->avisosLancada($this->preLoteB));
    }

    public function test_compras_nao_recebe_o_aviso(): void
    {
        $this->lancar($this->recebimento, ['numero_nota' => '5002']);

        $this->assertCount(0, $this->avisosLancada($this->compras));
    }

    public function test_quem_lancou_nao_recebe_o_proprio_aviso(): void
    {
        $this->lancar($this->recebimento, ['numero_nota' => '5003']);

        $this->assertCount(0, $this->avisosLancada($this->recebimento));
    }

    public function test_pre_lote_lancando_nao_avisa_os_colegas(): void
    {
        // Nota antecipada: quem lança já é do setor que analisa — seria ruído
        $this->lancar($this->preLoteA, ['numero_nota' => '5004', 'origem' => 'pre_lote']);

        $this->assertCount(0, $this->avisosLancada($this->preLoteB));
    }

    public function test_aviso_some_quando_o_pre_lote_abre_card(): void
    {
        $this->lancar($this->recebimento, ['numero_nota' => '5005']);
        $nota = Nota::where('numero_nota', '5005')->first();

        $this->actingAs($this->preLoteA)
            ->post(route('notas.cards.store', $nota), ['tipo' => 'cadastro']);

        // Analisou: a nota não é mais "nova" para ninguém do pré-lote
        $this->assertCount(0, $this->avisosLancada($this->preLoteA));
        $this->assertCount(0, $this->avisosLancada($this->preLoteB));
    }

    public function test_aviso_some_quando_a_nota_e_liberada(): void
    {
        $this->lancar($this->recebimento, ['numero_nota' => '5006']);
        $nota = Nota::where('numero_nota', '5006')->first();

        $this->actingAs($this->preLoteA)->post(route('notas.liberar', $nota));

        $this->assertCount(0, $this->avisosLancada($this->preLoteA));
        $this->assertCount(0, $this->avisosLancada($this->preLoteB));
    }

    public function test_aviso_some_quando_a_nota_e_cancelada(): void
    {
        $this->lancar($this->recebimento, ['numero_nota' => '5007']);
        $nota = Nota::where('numero_nota', '5007')->first();

        $this->actingAs($this->preLoteA)->post(route('notas.cancelar', $nota));

        $this->assertCount(0, $this->avisosLancada($this->preLoteB));
    }

    public function test_caminhao_chegando_com_nota_do_pre_lote_avisa(): void
    {
        $forn = Fornecedor::firstOrCreate(['nome' => 'CHUA']);
        // Antecipada, lançada pelo próprio pré-lote (sem aviso nenhum)
        $this->lancar($this->preLoteA, ['numero_nota' => '5008', 'origem' => 'pre_lote']);
        $this->assertCount(0, $this->avisosLancada($this->preLoteB));

        // Agora o caminhão chegou: o recebimento move para "caminhão na porta"
        $this->actingAs($this->recebimento)->post(route('notas.store'), [
            'numero_nota' => '5008', 'fornecedor_id' => $forn->id,
            'loja' => 1, 'origem' => 'recebimento', 'confirmar_mover' => true,
        ])->assertRedirect();

        $this->assertCount(1, $this->avisosLancada($this->preLoteB));
    }

    public function test_quem_desligou_o_sino_nao_recebe(): void
    {
        $this->preLoteB->update(['notificacoes_ativas' => false]);

        $this->lancar($this->recebimento, ['numero_nota' => '5009']);

        $this->assertCount(1, $this->avisosLancada($this->preLoteA));
        $this->assertCount(0, $this->avisosLancada($this->preLoteB));
    }
}
