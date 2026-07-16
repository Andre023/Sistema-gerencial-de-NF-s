<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remodelagem do fluxo real (jul/2026): a nota deixa de ter um "motivo" único
     * e passa a acumular CARDS de divergência, cada um com seu ciclo
     * (aberto → corrigido pelo comprador → resolvido pelo pré-lote).
     *
     * As tabelas antigas (requisicoes, cadastros) ficam no banco como histórico;
     * o código deixa de usá-las.
     */
    public function up(): void
    {
        Schema::create('notas', function (Blueprint $table) {
            $table->id();
            $table->string('numero_nota')->index();

            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users'); // quem lançou

            $table->integer('loja');

            // recebimento = caminhão na porta (prioridade) | pre_lote = antecipada
            $table->string('origem')->default('recebimento');

            $table->text('observacao')->nullable();

            // Liberação é ato explícito do pré-lote — o "✅" do grupo
            $table->foreignId('liberada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('liberada_em')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['liberada_em', 'created_at']);
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nota_id')->constrained('notas')->cascadeOnDelete();

            $table->string('tipo');                      // cadastro | regra | custo | quantidade
            $table->string('status')->default('aberto'); // aberto | corrigido | resolvido
            $table->text('detalhe')->nullable();         // qual item, qual regra...

            // Igual comentários/auditoria: se o usuário sair, o histórico permanece
            $table->foreignId('aberto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrigido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrigido_em')->nullable();
            $table->foreignId('resolvido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolvido_em')->nullable();

            // Quantas vezes o pré-lote reabriu por ainda estar errado
            $table->unsignedSmallInteger('reaberturas')->default(0);

            $table->timestamps();

            $table->index(['nota_id', 'status']);
        });

        // Papéis funcionais no lugar da hierarquia genérica:
        // encarregado → pre_lote, operador → recebimento (admin fica).
        // Compras precisa ser atribuído na tela de usuários (não dá para adivinhar).
        DB::table('users')->where('role', 'encarregado')->update(['role' => 'pre_lote']);
        DB::table('users')->where('role', 'operador')->update(['role' => 'recebimento']);
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
        Schema::dropIfExists('notas');

        DB::table('users')->where('role', 'pre_lote')->update(['role' => 'encarregado']);
        DB::table('users')->where('role', 'recebimento')->update(['role' => 'operador']);
    }
};
