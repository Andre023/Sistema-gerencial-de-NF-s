<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Duas necessidades novas (colunas ADITIVAS — não tocam em dado existente):
     *
     * 1. CANCELAMENTO — o fornecedor cancelou a NF. A nota sai da fila e vai para
     *    a seção "Canceladas neste dia", sem ser excluída: o histórico continua
     *    inteiro para as estatísticas (ver docs/NOTAS_CANCELADAS.md).
     *
     * 2. ORIGEM ALTERADA — quando a nota muda de fila (pré-lote → caminhão na
     *    porta), o relógio do envelhecimento reinicia: ela esperou no pré-lote,
     *    não no recebimento. Guardamos QUANDO mudou e DE ONDE veio, para a tela
     *    mostrar "Pré-lote desde 19/06" e as cores seguirem a fila atual.
     */
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->timestamp('cancelada_em')->nullable()->after('recebida_em');
            $table->foreignId('cancelada_por')->nullable()->after('cancelada_em')
                ->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelamento', 500)->nullable()->after('cancelada_por');

            $table->timestamp('origem_alterada_em')->nullable()->after('origem');
            $table->string('origem_anterior')->nullable()->after('origem_alterada_em');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelada_por');
            $table->dropColumn([
                'cancelada_em', 'motivo_cancelamento',
                'origem_alterada_em', 'origem_anterior',
            ]);
        });
    }
};
