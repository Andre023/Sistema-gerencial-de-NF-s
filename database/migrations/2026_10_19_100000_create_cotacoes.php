<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Cotação CEASA: as listas de estoque baixo das lojas, a lista que vai
     * aos fornecedores, as respostas deles e a planilha em que compras faz o
     * pedido.
     *
     * Antes era um HTML avulso que guardava tudo no navegador de quem abriu —
     * outro PC, outra cotação. Aqui fica no servidor e todos os compradores
     * mexem na mesma.
     */
    public function up(): void
    {
        Schema::create('cotacoes', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 120);
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /*
         * Cada planilha da cotação é uma folha: as listas de compra, a prévia,
         * as respostas dos fornecedores e a planilha de compra de cada loja.
         *
         * Colunas e linhas vão em JSON, e não numa tabela de células, porque a
         * folha é sempre lida e gravada inteira — algumas centenas de linhas —
         * e a tabela de células multiplicaria as linhas do banco por treze sem
         * ganhar nenhuma consulta que alguém faça.
         */
        Schema::create('cotacao_folhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotacao_id')->constrained('cotacoes')->cascadeOnDelete();
            $table->string('tipo', 20);   // lista | previa | resposta | compra
            $table->string('nome', 120);
            $table->string('arquivo')->nullable();
            $table->json('meta')->nullable();
            $table->json('colunas');
            $table->json('linhas');

            // Conta as alterações. É o que deixa a tela de cada comprador
            // aplicar as dos outros na ordem em que o servidor gravou — e
            // perceber quando perdeu alguma.
            $table->unsignedInteger('versao')->default(0);

            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();

            $table->index(['cotacao_id', 'tipo', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotacao_folhas');
        Schema::dropIfExists('cotacoes');
    }
};
