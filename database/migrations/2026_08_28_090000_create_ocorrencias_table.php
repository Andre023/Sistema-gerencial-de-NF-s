<?php

use App\Models\Nota;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O livro de ocorrências da nota — o que aconteceu, quem fez e quando.
     *
     * Por que uma tabela, se a linha do tempo dos comentários já mostrava os
     * cards: porque aquela linha era DEDUZIDA dos dados vivos, e por isso só
     * conseguia mostrar o que ainda existe. Justamente as ações que mais pedem
     * um responsável eram as que apagavam a própria prova:
     *
     *   • comentário apagado  — a linha some do banco e não deixa rastro
     *   • card excluído       — somem junto "abriu", "corrigiu" e "resolveu"
     *   • descancelar         — cancelada_em volta a nulo; o motivo se perde
     *   • devolver            — liberada_em volta a nulo; o "fulano liberou" some
     *   • editar observação   — sobrevive só o valor final
     *
     * Daí as três regras desta tabela:
     *
     *   1. Só cresce. Nada aqui é editado ou apagado — nem pela aplicação, nem
     *      quando a nota é excluída (ver nota_id abaixo).
     *   2. Guarda o ANTES. É o que a dedução nunca teve como saber.
     *   3. Grava na hora da ação, e não depois. Só quem executa sabe o que havia.
     */
    public function up(): void
    {
        Schema::create('ocorrencias', function (Blueprint $table) {
            $table->id();

            /*
             * Sem constraint para a nota, de propósito.
             *
             * A nota usa SoftDeletes hoje, mas basta alguém rodar uma limpeza de
             * verdade para o histórico ir junto — e "quem excluiu esta nota" é
             * exatamente a ocorrência que não pode sumir com ela. O índice
             * abaixo dá a busca; a integridade aqui vale menos que a memória.
             */
            $table->unsignedBigInteger('nota_id')->index();

            // Mesma regra dos comentários: se a conta sair, o histórico fica e o
            // autor vira "—". Nulo também é o sistema agindo sozinho (o job que
            // limpa anexos), que não tem usuário nenhum por trás.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // O verbo, em snake_case (nota_lancada, card_aberto, observacao_editada...).
            // String e não enum: enum vira migration a cada verbo novo, e o custo
            // de um verbo desconhecido aqui é um rótulo feio — não um erro.
            $table->string('acao', 40);

            /*
             * O corpo da ocorrência: o antes, o depois e o que mais aquele verbo
             * precisa (o tipo do card, o texto do comentário apagado, o motivo do
             * cancelamento). JSON porque cada verbo carrega um conjunto diferente
             * — colunas fixas dariam uma tabela larga e quase toda vazia.
             */
            $table->json('dados')->nullable();

            $table->timestamps();

            // A consulta é sempre "as ocorrências desta nota, em ordem".
            $table->index(['nota_id', 'created_at'], 'ocorrencias_da_nota_index');
        });

        $this->preencherHistorico();
    }

    /**
     * O passado que ainda dá para reconstruir.
     *
     * A tabela nasceria vazia, e as notas de hoje para trás perderiam a linha do
     * tempo que os comentários mostravam — que é o oposto do objetivo. Então
     * deduzimos aqui, uma última vez, o que os dados ainda contam: lançamento,
     * ciclo dos cards, liberação e cancelamento.
     *
     * O que não dá para recuperar (edições e exclusões passadas) segue perdido —
     * é justamente por isso que esta tabela passa a existir. Daqui para a frente
     * ninguém mais deduz nada.
     */
    private function preencherHistorico(): void
    {
        $agora = now();
        $linhas = [];

        $anotar = function (int $notaId, ?int $userId, string $acao, ?array $dados, $em) use (&$linhas, $agora) {
            if (! $em) {
                return;
            }

            $linhas[] = [
                'nota_id'    => $notaId,
                'user_id'    => $userId,
                'acao'       => $acao,
                'dados'      => $dados ? json_encode($dados) : null,
                // created_at é QUANDO ACONTECEU, não quando esta migration rodou:
                // é o que mantém a ordem da linha do tempo fiel ao que se viu.
                'created_at' => $em,
                'updated_at' => $agora,
            ];
        };

        // withTrashed: a nota excluída também tem história, e ela é a que mais
        // interessa a quem for procurar aqui depois.
        Nota::withTrashed()
            ->with(['cards'])
            ->chunkById(200, function ($notas) use ($anotar, &$linhas) {
                foreach ($notas as $nota) {
                    $anotar($nota->id, $nota->user_id, 'nota_lancada', null, $nota->created_at);

                    foreach ($nota->cards as $card) {
                        $tipo = ['tipo' => $card->tipo];

                        $anotar($nota->id, $card->aberto_por, 'card_aberto', $tipo, $card->created_at);
                        $anotar($nota->id, $card->corrigido_por, 'card_corrigido', $tipo, $card->corrigido_em);

                        // Corrigir já resolve o card, e os dois carimbos ficam no
                        // mesmo instante. Repetir viraria duas linhas dizendo o
                        // mesmo — some a segunda.
                        $mesmoInstante = $card->corrigido_em
                            && $card->resolvido_em
                            && $card->corrigido_em->equalTo($card->resolvido_em);

                        if (! $mesmoInstante) {
                            $anotar($nota->id, $card->resolvido_por, 'card_resolvido', $tipo, $card->resolvido_em);
                        }
                    }

                    $anotar($nota->id, $nota->liberada_por, 'nota_liberada', null, $nota->liberada_em);
                    $anotar(
                        $nota->id,
                        $nota->cancelada_por,
                        'nota_cancelada',
                        $nota->motivo_cancelamento ? ['motivo' => $nota->motivo_cancelamento] : null,
                        $nota->cancelada_em,
                    );
                }

                // Grava por lote: numa VM de 1 GB, um insert por linha faria a
                // migration levar minutos com o site no ar.
                foreach (array_chunk($linhas, 500) as $lote) {
                    DB::table('ocorrencias')->insert($lote);
                }

                $linhas = [];
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocorrencias');
    }
};
