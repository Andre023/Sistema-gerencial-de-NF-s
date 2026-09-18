<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mais duas marcas de fornecedor, irmãs de `consignado`: "feira" e "uso e
     * consumo". Mesma regra — é do fornecedor, não da nota, e toda nota dele
     * ganha o selo junto do número. Uma coluna por marca, e não um enum: um
     * fornecedor pode ser as três coisas ao mesmo tempo.
     */
    public function up(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->boolean('feira')->default(false)->after('consignado')->index();
            $table->boolean('uso_consumo')->default(false)->after('feira')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->dropColumn(['feira', 'uso_consumo']);
        });
    }
};
