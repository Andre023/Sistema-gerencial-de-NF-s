<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fornecedor "consignado": marcado em Configurações › Consignados. Toda nota
     * dele ganha um selo antes do nome na fila, sem ninguém precisar marcar nada
     * ao lançar — ao contrário do CEASA, que é da nota, isto é do fornecedor.
     * Indexado porque a seção lista só os marcados.
     */
    public function up(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->boolean('consignado')->default(false)->after('prioridade')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->dropColumn('consignado');
        });
    }
};
