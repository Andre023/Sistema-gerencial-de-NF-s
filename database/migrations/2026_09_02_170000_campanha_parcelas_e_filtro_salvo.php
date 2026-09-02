<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Duas coisas que andam juntas na mesma tela.
     *
     * ── PARCELAS ───────────────────────────────────────────────────────────
     * O `pago` era um valor só, digitado na mão. Fornecedor grande paga
     * parcelado, e com um número só a informação "entrou R$ 10.000" não dizia
     * se foi uma vez ou quatro, nem quando — que é exatamente o que se precisa
     * saber para cobrar o resto.
     *
     * A partir daqui as parcelas são a VERDADE, e `pago` passa a ser a soma
     * delas. A coluna fica (não vira cálculo na hora) porque a lista, a
     * ordenação e a exportação leem esse total o tempo todo: recalcular a cada
     * leitura trocaria uma escrita por dezenas de somas.
     *
     * O que já existia não se perde: todo `pago` maior que zero vira UMA parcela,
     * datada do dia em que o atendimento foi criado — que é a melhor data que
     * temos, já que a antiga não guardava nenhuma.
     *
     * ── FILTRO SALVO ───────────────────────────────────────────────────────
     * O filtro por comprador vira preferência da conta, e não do navegador: quem
     * acompanha os próprios fornecedores reabre a aba no mesmo lugar, inclusive
     * de outra máquina.
     */
    public function up(): void
    {
        Schema::create('campanha_parcelas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campanha_atendimento_id')
                ->constrained('campanha_atendimentos')
                // A parcela não existe sem o atendimento: tirar o fornecedor da
                // lista leva o histórico de pagamento dele junto.
                ->cascadeOnDelete();

            $table->decimal('valor', 15, 2);
            $table->date('data');

            $table->timestamps();

            // A consulta é sempre "as parcelas deste atendimento, em ordem de data".
            $table->index(['campanha_atendimento_id', 'data']);
        });

        // O que já estava pago vira a primeira parcela — nada se perde.
        foreach (DB::table('campanha_atendimentos')->where('pago', '>', 0)->get() as $a) {
            DB::table('campanha_parcelas')->insert([
                'campanha_atendimento_id' => $a->id,
                'valor'      => $a->pago,
                // A tabela antiga não guardava data. A da inclusão é a única
                // informação real que existe, e mentir uma data de hoje seria
                // pior do que usar a que se sabe.
                'data'       => substr((string) $a->created_at, 0, 10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            // null = "Todos". FK para o próprio users: se a conta filtrada sair,
            // a preferência volta sozinha para "Todos" em vez de apontar para um
            // comprador que não existe mais.
            $table->foreignId('campanha_filtro_comprador')
                ->nullable()
                ->after('notificacoes_ativas')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campanha_filtro_comprador');
        });

        Schema::dropIfExists('campanha_parcelas');
    }
};
