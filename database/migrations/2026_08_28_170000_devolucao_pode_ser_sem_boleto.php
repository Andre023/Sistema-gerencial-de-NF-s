<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devolução que não vai ter boleto nenhum.
     *
     * Um campo próprio, e não a lista de vencimentos vazia, porque as duas
     * situações já existiam e são diferentes na doca:
     *
     *   lista vazia  → o boleto ainda NÃO SAIU. Alguém vai cobrar a data depois.
     *   sem_boleto   → não haverá boleto. Não há o que esperar.
     *
     * Sem a distinção, quem confere o quadro não sabe se aquele card está
     * esperando alguma coisa — que é justamente a pergunta que o quadro existe
     * para responder.
     *
     * Só acrescenta coluna; nada é lido nem apagado. O default `false` já
     * descreve corretamente as devoluções que existem: todas foram lançadas com
     * boleto.
     */
    public function up(): void
    {
        Schema::table('devolucoes', function (Blueprint $table) {
            $table->boolean('sem_boleto')->default(false)->after('boletos_vencem');
        });
    }

    public function down(): void
    {
        Schema::table('devolucoes', function (Blueprint $table) {
            $table->dropColumn('sem_boleto');
        });
    }
};
