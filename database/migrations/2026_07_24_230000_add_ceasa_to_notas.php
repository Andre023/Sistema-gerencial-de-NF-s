<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nota de CEASA (hortifruti do atacado). Vem desmarcada por padrão; quando
     * marcada, o setor de compras também pode abrir cards nela — nas demais,
     * só o pré-lote abre.
     */
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->boolean('ceasa')->default(false)->after('origem');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn('ceasa');
        });
    }
};
