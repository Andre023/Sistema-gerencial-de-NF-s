<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os fornecedores que cada comprador já atendeu na campanha.
     *
     * Até aqui a aba Campanha só montava a carta e a entregava — não guardava
     * nada. Quem tinha mandado para quem, e quanto do combinado já entrou,
     * vivia na cabeça do comprador ou numa planilha à parte.
     *
     * ── Por que os valores ficam COPIADOS aqui ─────────────────────────────
     * `faturamento` e `investimento` são gravados nesta linha em vez de lidos
     * da campanha_fornecedores na hora de mostrar. Parece redundância, mas é o
     * contrário: a planilha de compras TROCA aquela tabela inteira a cada
     * envio, e o faturamento muda junto. Sem a cópia, o acordo fechado em
     * agosto passaria a ser cobrado pelo faturamento de setembro — a meta se
     * mexeria sozinha depois de combinada, e o "quanto falta" mentiria.
     *
     * O que foi combinado com o fornecedor é um fato daquele dia. Fica.
     */
    public function up(): void
    {
        Schema::create('campanha_atendimentos', function (Blueprint $table) {
            $table->id();

            // De quem é a lista. Cada comprador acompanha os seus.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('fornecedor');
            // A forma comparável do nome (CampanhaFornecedor::chaveDe), para
            // reconhecer o mesmo parceiro escrito de dois jeitos.
            $table->string('chave', 200)->index();

            // O faturamento que valia quando o acordo foi feito.
            $table->decimal('faturamento', 15, 2)->nullable();
            // A meta: o percentual sugerido aplicado ao faturamento acima. Fica
            // editável na tela — nem todo acordo fecha exatamente nos 2%.
            $table->decimal('investimento', 15, 2);
            // Quanto o fornecedor já pagou. Nasce zero e vai subindo.
            $table->decimal('pago', 15, 2)->default(0);

            $table->timestamps();

            // A consulta é sempre "os meus, do mais novo para o mais antigo".
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanha_atendimentos');
    }
};
