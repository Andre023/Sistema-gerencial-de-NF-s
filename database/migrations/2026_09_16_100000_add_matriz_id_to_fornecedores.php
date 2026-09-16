<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriz e filial do mesmo fornecedor.
 *
 * O mesmo fornecedor entra duas vezes na base — "MOINHO GLOBO S.A." e "MOINHO
 * GLOBO S/A", ou a matriz e a filial com CNPJs diferentes — e cada metade do
 * histórico fica com um nome. Renomear não resolve: `nome` é único, e os dois
 * CNPJs são reais.
 *
 * A filial aponta para a matriz. Ao vincular, as notas da filial passam para a
 * matriz (ver FornecedorVinculoController), então quem agrupa por fornecedor_id
 * — estatísticas, dossiê, reincidência — soma os dois sem saber que houve
 * vínculo. A filial continua existindo para que buscar pelo nome dela
 * encontre a matriz.
 *
 * Só um nível: matriz não tem matriz_id. Isso é regra do controller, não do
 * banco — o MySQL não tem CHECK que olhe outra linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->foreignId('matriz_id')->nullable()->after('cnpj')
                ->constrained('fornecedores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matriz_id');
        });
    }
};
