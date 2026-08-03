<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice para o filtro que TODA consulta do sistema faz (ADITIVO — cria uma
     * estrutura de busca ao lado, não lê nem reescreve nenhuma linha).
     *
     * Três condições aparecem juntas em praticamente toda query da fila e das
     * estatísticas:
     *
     *     deleted_at IS NULL      ← o SoftDeletes acrescenta sozinho, sempre
     *     cancelada_em IS NULL    ← o escopo ativas() (docs/NOTAS_CANCELADAS.md)
     *     created_at <= X / BETWEEN
     *
     * Hoje não existe índice que cubra isso, então cada abertura da fila e cada
     * bloco das estatísticas varre a tabela inteira. Hoje ela é pequena e ninguém
     * sente; ela só cresce, e nota nunca é apagada.
     *
     * A ORDEM das colunas é o que faz o índice servir: primeiro as duas
     * comparações de igualdade, depois a coluna de intervalo e de ordenação.
     * Invertida, o MySQL não consegue usar o índice para o BETWEEN nem para o
     * ORDER BY created_at.
     *
     * Ficaram DE FORA de propósito:
     *   • loja e origem — 9 e 2 valores possíveis. Pouco seletivo para o
     *     otimizador usar, e todo índice custa escrita em cada INSERT/UPDATE.
     *   • um índice só para cancelada_em — as consultas de cancelamento também
     *     carregam o deleted_at IS NULL do SoftDeletes, então já são atendidas
     *     pelo prefixo (deleted_at, cancelada_em) deste mesmo índice.
     */
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->index(['deleted_at', 'cancelada_em', 'created_at'], 'notas_fila_index');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropIndex('notas_fila_index');
        });
    }
};
