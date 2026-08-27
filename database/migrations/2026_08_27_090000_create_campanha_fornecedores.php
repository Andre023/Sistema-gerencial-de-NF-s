<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A base de faturamento da campanha: o ranking de compras que veio do ERP.
 *
 * É uma FOTOGRAFIA, não um cadastro — cada envio de planilha substitui a tabela
 * inteira. Por isso não tem relação com `fornecedores`: o nome aqui é o que a
 * planilha trouxe, e é ele que o comprador leva para a carta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campanha_fornecedores', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 200);

            // Nome normalizado (maiúsculas, sem acento, espaços colapsados) —
            // é por ele que a tela reconhece o fornecedor digitado à mão.
            $table->string('chave', 200)->index();

            // Valor de compra dos últimos 12 meses. 15,2 cobre com folga o
            // maior fornecedor da base (R$ 21 milhões) e o total geral.
            $table->decimal('faturamento', 15, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanha_fornecedores');
    }
};
