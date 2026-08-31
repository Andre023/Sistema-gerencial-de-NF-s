<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Nota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A resposta de uma ação na nota, nos dois formatos.
 *
 * ── Por que dois ───────────────────────────────────────────────────────────
 * Toda ação era POST → 302 → GET da fila inteira. Medido em produção: o
 * servidor montava 303 ms e 158 KB (48 notas na fila + 137 liberadas do dia)
 * para entregar 0,90 KB de informação — a única nota que mudou. E piorava ao
 * longo do dia: cada nota liberada deixava todas as ações seguintes mais caras,
 * porque cada uma remontava a lista inteira.
 *
 * Quando a tela pode aplicar só a linha alterada, ela pede JSON e recebe a nota
 * pronta. Uma viagem em vez de duas, 5 ms em vez de 303.
 *
 * ── Quando o formato antigo continua valendo ───────────────────────────────
 * Com filtro ativo a tela NÃO pode aplicar sozinha: ela não tem como saber se a
 * nota alterada ainda pertence à lista filtrada. Aí ela não pede JSON, e o
 * redirect de sempre responde. O mesmo vale para quem não usa JavaScript e para
 * os testes que já existiam — nada deles muda.
 */
trait RespondeAcaoDeNota
{
    /**
     * Ação concluída.
     *
     * O `sucesso` viaja junto no JSON porque o toast da tela nasce do `flash` do
     * Inertia, que não existe numa resposta de axios: sem isto, o "Card
     * resolvido." simplesmente não apareceria para quem está no caminho novo.
     */
    protected function acaoConcluida(
        Request $request,
        Nota $nota,
        string $sucesso,
    ): RedirectResponse|JsonResponse {
        if (! $request->expectsJson()) {
            return back()->with('sucesso', $sucesso);
        }

        return response()->json([
            'nota'    => $nota->paraTelaAgora(),
            'sucesso' => $sucesso,
        ]);
    }

    /**
     * Ação recusada por uma regra do negócio (não por validação de campo).
     *
     * 422 e não 400: é o mesmo código que o Laravel usa para validação, então o
     * tratamento de erro que a tela já tem para os outros pedidos em axios
     * (devoluções, comentários) serve sem caso especial.
     *
     * @param array<string,string> $erros
     */
    protected function acaoRecusada(Request $request, array $erros): RedirectResponse|JsonResponse
    {
        if (! $request->expectsJson()) {
            return back()->withErrors($erros);
        }

        return response()->json(['erro' => reset($erros)], 422);
    }
}
