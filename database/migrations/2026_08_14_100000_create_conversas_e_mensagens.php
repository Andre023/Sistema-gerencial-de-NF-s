<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversa direta entre duas pessoas — o "WhatsApp" interno do sistema.
     *
     * Três tabelas: a conversa, quem participa dela, e as mensagens.
     *
     * Por que a conversa é uma tabela e não só um par de colunas na mensagem:
     * é onde mora o que pertence à conversa inteira e não a uma linha dela —
     * até onde cada um leu, e quando ela recebeu a última mensagem (que é como
     * a lista da barra se ordena, sem varrer mensagem nenhuma).
     */
    public function up(): void
    {
        Schema::create('conversas', function (Blueprint $table) {
            $table->id();

            // Hoje só existe 'direta'. A coluna nasce aqui para que conversa em
            // grupo entre depois como valor novo, sem migração de dados.
            $table->string('tipo', 20)->default('direta');

            /*
             * A trava que impede conversa duplicada.
             *
             * "menorId-maiorId" (ex.: "3-17") é a mesma string independente de
             * quem clicou primeiro. Sem ela, André e Maria se abrindo no mesmo
             * segundo criariam DUAS conversas — e cada um mandaria mensagem para
             * uma metade, sem nunca entender por que o outro não responde.
             * O unique deixa o banco recusar a segunda; o model trata e devolve
             * a que já existe.
             *
             * Nula em conversa de grupo (quando existir): lá não há par.
             */
            $table->string('chave_direta', 40)->nullable()->unique();

            // Ordenação da lista. Indexada porque é o ORDER BY de toda abertura
            // da barra lateral.
            $table->timestamp('ultima_mensagem_em')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('conversa_participantes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversa_id')->constrained('conversas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * Até onde esta pessoa leu — o id da última mensagem que ela viu.
             *
             * Um ponteiro só, em vez de uma linha por mensagem lida: o não lido
             * é "mensagens com id maior que este", uma comparação de inteiros.
             * É também o que acende o ✓✓ do outro lado.
             *
             * Sem FK de propósito: se a mensagem apontada for apagada, o
             * ponteiro continua válido como marca d'água (id > n segue certo).
             */
            $table->unsignedBigInteger('lida_ate_id')->nullable();

            $table->timestamps();

            // Ninguém participa duas vezes da mesma conversa
            $table->unique(['conversa_id', 'user_id']);

            // "minhas conversas" — a consulta que abre a barra
            $table->index('user_id');
        });

        Schema::create('mensagens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversa_id')->constrained('conversas')->cascadeOnDelete();

            // Igual a comentários e anexos: se a conta sair, a conversa fica
            // legível — a mensagem passa a ser de "usuário removido".
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Nulo quando a mensagem é só um arquivo, sem legenda
            $table->text('texto')->nullable();

            /*
             * O anexo mora AQUI, e não numa tabela à parte, porque é 0 ou 1 por
             * mensagem — cada foto enviada vira uma bolha própria, como no
             * WhatsApp. Tabela separada só acrescentaria um join a cada abertura
             * de conversa para representar uma relação que nunca é "muitos".
             *
             * `anexo_caminho` é único: é o endereço físico do arquivo, e dois
             * registros apontando para o mesmo faria a exclusão de um deixar o
             * outro quebrado. (Nulo repetido é permitido pelo MySQL.)
             */
            $table->string('anexo_caminho')->nullable()->unique();
            $table->string('anexo_nome')->nullable();
            $table->string('anexo_mime', 100)->nullable();
            $table->unsignedInteger('anexo_tamanho')->nullable();

            /*
             * Quando o arquivo saiu do disco do servidor.
             *
             * O anexo vive poucos dias aqui (Mensagem::DIAS_NO_SERVIDOR). Passado
             * o prazo, a faxina noturna apaga o arquivo e carimba esta coluna —
             * o registro sobrevive só para a conversa continuar fazendo sentido
             * ("Fulano enviou orcamento.pdf"). Daí em diante quem exibe o
             * conteúdo é o navegador de quem já tinha aberto: cada máquina
             * guarda a própria cópia ao ver o arquivo pela primeira vez.
             */
            $table->timestamp('anexo_removido_em')->nullable();

            $table->timestamps();

            // Paginação da thread: "as últimas N desta conversa"
            $table->index(['conversa_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens');
        Schema::dropIfExists('conversa_participantes');
        Schema::dropIfExists('conversas');
    }
};
