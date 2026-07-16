<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comentários de um registro (requisição, cadastro...).
     *
     * Polimórfico de propósito: o operador não pode editar campos — só criar e
     * atender — então sem isto ele não tem onde registrar contexto ("liguei pro
     * fornecedor, retorna amanhã"). Cadastros ganham o mesmo canal sem tabela nova.
     */
    public function up(): void
    {
        Schema::create('comentarios', function (Blueprint $table) {
            $table->id();
            $table->morphs('comentavel'); // comentavel_type + comentavel_id (já indexados juntos)

            // Igual à auditoria: se o usuário sair, o histórico permanece (autor vira "—")
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('texto');
            $table->timestamps();

            $table->index(['comentavel_type', 'comentavel_id', 'created_at'], 'comentarios_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comentarios');
    }
};
