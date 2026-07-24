<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avisos dirigidos a uma pessoa, seguindo o ciclo do card:
     *
     *   pré-lote abre card  → avisa compras
     *   compras corrige     → avisa quem abriu o card
     *   pré-lote reabre     → avisa compras de novo
     *   pré-lote libera     → avisa quem lançou a nota
     *
     * Duas datas de "fim", porque são coisas diferentes:
     *   lida_em      → a pessoa viu
     *   encerrada_em → o motivo deixou de existir (card resolvido, nota liberada)
     * Isso é o que o grupo do WhatsApp não faz: lá a mensagem fica pendurada
     * mesmo depois de outra pessoa ter resolvido.
     */
    public function up(): void
    {
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('nota_id')->constrained('notas')->cascadeOnDelete();

            $table->string('tipo'); // divergencia | corrigido | reaberto | liberada

            // { tipos: ['custo','cadastro'], autor: 'Fulano' } — o texto é montado na tela
            $table->json('dados')->nullable();

            $table->timestamp('lida_em')->nullable();
            $table->timestamp('encerrada_em')->nullable();

            $table->timestamps();

            // O sino busca sempre "as minhas, vivas, mais recentes primeiro"
            $table->index(['user_id', 'encerrada_em', 'lida_em']);
            // Acumular na notificação viva em vez de criar outra (uma por nota+tipo)
            $table->index(['nota_id', 'tipo']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notificacoes_ativas')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacoes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notificacoes_ativas');
        });
    }
};
