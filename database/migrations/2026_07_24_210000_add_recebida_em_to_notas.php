<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando o caminhão chega com uma nota que JÁ foi liberada no pré-lote
     * (às vezes em outro dia), a nota não é duplicada: registramos a chegada
     * física aqui, sem mexer em quem/quando liberou. A seção "liberadas neste
     * dia" passa a mostrar tanto o que foi liberado hoje quanto o que chegou hoje.
     */
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->timestamp('recebida_em')->nullable()->after('liberada_em');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn('recebida_em');
        });
    }
};
