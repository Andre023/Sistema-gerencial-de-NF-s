<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O card "Trocar nota" deixa de existir; o que havia nele vira RECUSA.
     *
     * Os dois diziam a mesma coisa por caminhos diferentes: se a nota tem de
     * ser trocada com o fornecedor, a mercadoria daquela nota não entra — que é
     * exatamente uma recusa. Manter os dois só dividia o mesmo fato em dois
     * cardões, e quem estava na doca tinha de adivinhar qual abrir.
     *
     * Nada é apagado: o card muda de tipo e leva junto o detalhe, quem abriu,
     * quem resolveu e as datas. O histórico da nota continua inteiro.
     */
    public function up(): void
    {
        // 1. Os cards.
        DB::table('cards')->where('tipo', 'trocar_nota')->update(['tipo' => 'recusa']);

        /*
         * 2. Os avisos do sino.
         *
         * O aviso guarda em `dados->tipos` a lista de cards que o motivaram. Sem
         * mexer aqui, um aviso vivo continuaria dizendo "TROCAR NOTA" — um tipo
         * que a tela já não conhece, e que apareceria cru na tela porque o
         * rótulo cai no `?? t`.
         *
         * `tipos` é JSON e vira lista de novo depois de trocada a palavra. O
         * `unique` importa: o aviso que citava recusa E trocar nota ficaria com
         * "recusa" repetido, e a pessoa leria "RECUSA, RECUSA".
         */
        foreach (DB::table('notificacoes')->whereNotNull('dados')->get() as $aviso) {
            $dados = json_decode((string) $aviso->dados, true);

            if (! is_array($dados) || ! isset($dados['tipos']) || ! is_array($dados['tipos'])) {
                continue;
            }

            if (! in_array('trocar_nota', $dados['tipos'], true)) {
                continue;
            }

            $dados['tipos'] = array_values(array_unique(array_map(
                fn($t) => $t === 'trocar_nota' ? 'recusa' : $t,
                $dados['tipos'],
            )));
            sort($dados['tipos']); // o Notificador grava sempre ordenado

            DB::table('notificacoes')
                ->where('id', $aviso->id)
                ->update(['dados' => json_encode($dados)]);
        }
    }

    /**
     * Sem volta, e de propósito.
     *
     * Depois da troca não há como saber quais recusas nasceram "trocar nota" —
     * a informação que separava as duas é justamente a que foi unificada.
     * Reverter no chute transformaria recusas legítimas num tipo que o sistema
     * já não aceita, o que é pior do que não reverter.
     *
     * Se for mesmo preciso desfazer, o caminho é o backup de antes do deploy
     * (scripts/backup.sh roda antes de toda subida).
     */
    public function down(): void
    {
        // intencionalmente vazio — ver o bloco acima
    }
};
