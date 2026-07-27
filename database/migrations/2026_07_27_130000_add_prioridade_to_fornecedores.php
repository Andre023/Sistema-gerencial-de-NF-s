<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fornecedor "prioritário": o admin marca na aba Prioridades. Quando uma nota
     * desse fornecedor entra no pré-lote, ela sobe direto para o topo da fila.
     * É um atributo do fornecedor (não da nota) — vale para todas as notas dele.
     * Indexado porque a fila ordena e a tela filtra por ele.
     */
    public function up(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->boolean('prioridade')->default(false)->after('nome')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->dropColumn('prioridade');
        });
    }
};
