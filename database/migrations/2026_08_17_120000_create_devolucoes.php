<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O quadro de devoluções entre pré-lote e recebimento.
     *
     * Substitui um recado de WhatsApp que hoje circula solto: "FAZER DEVOLUÇÃO
     * COM BOLETO / NOTA / FORNECEDOR / MOTIVO / AUTORIZADO POR / BOLETO VENCE",
     * com o print do sistema em anexo. No WhatsApp isso se perde na rolagem,
     * ninguém sabe o que já foi conferido, e o print some quando o telefone
     * enche.
     *
     * NÃO é o card 'devolucao' da nota. Aquele marca uma nota da fila cuja
     * mercadoria volta; este é um aviso independente, que nasce e morre no
     * quadro e não trava nota nenhuma. Por isso tabela própria, e não mais um
     * tipo de card.
     */
    public function up(): void
    {
        Schema::create('devolucoes', function (Blueprint $table) {
            $table->id();

            // Solto e não FK: o fornecedor vem do mesmo cadastro das notas, mas
            // o aviso precisa poder ser lançado com um nome que ainda não está
            // lá — a devolução não pode esperar cadastro.
            $table->string('fornecedor');
            $table->string('numero_nota', 60);
            $table->string('motivo');
            $table->string('autorizado_por');

            // Só a data: o que importa é o dia de vencer, e hora aqui só criaria
            // dúvida sobre fuso na hora de comparar com "hoje".
            $table->date('boleto_vence')->nullable();

            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Conferida: alguém do outro lado olhou e deu por resolvido.
             *
             * É o que move o card para o fim do quadro e o que começa a contar
             * a semana até os arquivos serem apagados (LimparAnexosDeDevolucao).
             */
            $table->timestamp('conferida_em')->nullable();
            $table->foreignId('conferida_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // O quadro ordena por "não conferidas primeiro, mais nova antes"
            $table->index(['conferida_em', 'id']);
        });

        Schema::create('devolucao_anexos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('devolucao_id')->constrained('devolucoes')->cascadeOnDelete();

            $table->string('caminho')->unique();  // relativo ao disco 'privado'
            $table->string('nome_original');
            $table->string('mime', 100);
            $table->unsignedInteger('tamanho');   // bytes

            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('devolucao_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devolucao_anexos');
        Schema::dropIfExists('devolucoes');
    }
};
