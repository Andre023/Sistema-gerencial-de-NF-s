<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quem atendeu e quando — antes disso o sistema usava user_id (quem CRIOU)
        // e updated_at (que muda a cada edição), ambos incorretos para "atendimento".
        Schema::table('requisicoes', function (Blueprint $table) {
            $table->foreignId('atendida_por')->nullable()->after('user_id')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('atendida_em')->nullable()->after('status');
        });

        Schema::table('cadastros', function (Blueprint $table) {
            $table->foreignId('atendida_por')->nullable()->after('user_id')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('atendida_em')->nullable()->after('status');
        });

        // ── Backfill de dados existentes ──────────────────────────────────────

        // atendida_em: melhor aproximação é o updated_at das já atendidas
        DB::table('requisicoes')->where('status', 'Atendida')
            ->update(['atendida_em' => DB::raw('updated_at')]);
        DB::table('cadastros')->where('status', 'Atendida')
            ->update(['atendida_em' => DB::raw('updated_at')]);

        // atendida_por: para requisições existe a trilha de auditoria — usamos o
        // último registro de ação "atendida" como fonte verdadeira do responsável.
        $atendimentos = DB::table('requisicao_auditorias')
            ->where('acao', 'atendida')
            ->orderBy('criado_em')
            ->get(['requisicao_id', 'user_id']);

        foreach ($atendimentos as $a) {
            DB::table('requisicoes')
                ->where('id', $a->requisicao_id)
                ->update(['atendida_por' => $a->user_id]);
        }
        // Cadastros não têm auditoria — atendida_por permanece null (desconhecido).
    }

    public function down(): void
    {
        Schema::table('requisicoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('atendida_por');
            $table->dropColumn('atendida_em');
        });

        Schema::table('cadastros', function (Blueprint $table) {
            $table->dropConstrainedForeignId('atendida_por');
            $table->dropColumn('atendida_em');
        });
    }
};
