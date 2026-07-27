<?php

namespace Tests\Feature;

use App\Models\Fornecedor;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fornecedores prioritários (aba só do admin) e o efeito na fila: uma nota de
 * fornecedor prioritário entra no TOPO do pré-lote, mesmo sendo mais recente.
 */
class PrioridadeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $preLote;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin   = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->preLote = User::factory()->create(['role' => User::ROLE_PRE_LOTE]);
    }

    private function notaDe(Fornecedor $forn, \DateTimeInterface $criadaEm): Nota
    {
        $nota = Nota::create([
            'numero_nota'   => (string) random_int(1000, 9999),
            'fornecedor_id' => $forn->id,
            'user_id'       => $this->preLote->id,
            'loja'          => 1,
            'origem'        => 'pre_lote',
        ]);
        $nota->created_at = $criadaEm;
        $nota->save();

        return $nota;
    }

    // ── Acesso ────────────────────────────────────────────────────────────────

    public function test_aba_e_so_do_admin(): void
    {
        $this->actingAs($this->preLote)->get(route('prioridades.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('prioridades.index'))->assertOk();
    }

    // ── Marcar / desmarcar ──────────────────────────────────────────────────────

    public function test_admin_marca_e_desmarca_fornecedor(): void
    {
        $forn = Fornecedor::create(['nome' => 'SPAL']);

        $this->actingAs($this->admin)
            ->patch(route('prioridades.alternar', $forn), ['prioridade' => true])
            ->assertRedirect();
        $this->assertTrue($forn->fresh()->prioridade);

        $this->actingAs($this->admin)
            ->patch(route('prioridades.alternar', $forn), ['prioridade' => false])
            ->assertRedirect();
        $this->assertFalse($forn->fresh()->prioridade);
    }

    public function test_nao_admin_nao_altera(): void
    {
        $forn = Fornecedor::create(['nome' => 'SPAL']);

        $this->actingAs($this->preLote)
            ->patch(route('prioridades.alternar', $forn), ['prioridade' => true])
            ->assertForbidden();

        $this->assertFalse($forn->fresh()->prioridade);
    }

    // ── Efeito na fila ──────────────────────────────────────────────────────────

    public function test_fornecedor_prioritario_vai_ao_topo_do_pre_lote(): void
    {
        $normal = Fornecedor::create(['nome' => 'Comum', 'prioridade' => false]);
        $vip    = Fornecedor::create(['nome' => 'Prioritario', 'prioridade' => true]);

        // A comum é MAIS ANTIGA (normalmente ficaria no topo por data)
        $antiga = $this->notaDe($normal, now()->subMinutes(30));
        // A prioritária é mais nova, mas deve subir ao topo
        $nova   = $this->notaDe($vip, now());

        $this->actingAs($this->preLote)
            ->get(route('notas.index'))
            ->assertInertia(fn($page) => $page
                ->where('preLote.0.id', $nova->id)
                ->where('preLote.0.fornecedor.prioridade', true)
                ->where('preLote.1.id', $antiga->id)
            );
    }
}
