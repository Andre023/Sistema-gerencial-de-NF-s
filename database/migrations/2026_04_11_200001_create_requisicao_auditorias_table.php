<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabela de auditoria — cada INSERT, UPDATE e DELETE gera um registro aqui.
     * Nunca deletamos desta tabela. É a memória histórica do sistema.
     */
    public function up(): void
    {
        Schema::create('requisicao_auditorias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requisicao_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();         // Quem fez a ação
            $table->string('acao');                                     // 'criada', 'editada', 'atendida', 'excluida'
            $table->json('dados_anteriores')->nullable();               // Estado antes da mudança
            $table->json('dados_novos')->nullable();                    // Estado depois da mudança
            $table->timestamp('criado_em')->useCurrent();

            // Sem foreign key restritiva: se o user for deletado, a auditoria permanece
            $table->index(['requisicao_id', 'criado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_auditorias');
    }
};
