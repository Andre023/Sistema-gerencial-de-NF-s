<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reações às mensagens do chat — o 👍 que responde sem virar mensagem nova.
 *
 * Tabela à parte, e não uma coluna JSON na mensagem: reagir é ação de OUTRA
 * pessoa sobre a linha de alguém. Numa coluna, dois colegas reagindo no mesmo
 * segundo leriam o mesmo JSON, cada um somaria a sua e o segundo gravaria por
 * cima da primeira — a reação some sem erro nenhum aparecer. Em linhas, cada
 * um insere a sua e o banco resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensagem_reacoes', function (Blueprint $table) {
            $table->id();

            /*
             * Cascade: apagada a mensagem, as reações vão junto.
             *
             * É o que faz a faxina de 21 dias (LimparMensagensAntigas) não
             * precisar saber que reações existem — ela apaga a mensagem e o
             * banco leva o resto. Uma reação órfã não teria a que se prender.
             */
            $table->foreignId('mensagem_id')->constrained('mensagens')->cascadeOnDelete();

            // Aqui é cascade e não nullOnDelete (ao contrário do autor da
            // mensagem): a mensagem sobrevive à conta removida porque o texto
            // ainda diz algo, mas um 👍 sem dono não diz nada — só infla o
            // contador com alguém que não existe mais.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Cabe qualquer emoji, inclusive os de vários codepoints (👍🏽 tem
            // dois, 👩‍🔧 tem quatro). 32 dá folga de sobra para os seis da barra.
            $table->string('emoji', 32);

            $table->timestamps();

            /*
             * Uma reação por pessoa por mensagem — como no WhatsApp.
             *
             * Sem isto, clicar duas vezes rápido no mesmo emoji gravaria duas
             * linhas e o contador mostraria "2" com uma pessoa só reagindo.
             * Trocar de emoji é UPDATE desta linha, não uma segunda.
             */
            $table->unique(['mensagem_id', 'user_id']);

            // "as reações desta página de mensagens", numa consulta só
            $table->index('mensagem_id');
        });

        /*
         * O índice que a faxina de 21 dias usa.
         *
         * Sem ele, "apagar o que passou do prazo" varre a tabela inteira toda
         * madrugada. Hoje são 231 linhas e não faria diferença; o índice existe
         * para quando forem 50 mil — que é justamente quando ninguém está
         * olhando o custo da faxina.
         */
        Schema::table('mensagens', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagem_reacoes');

        Schema::table('mensagens', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
