<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança em toda resposta da aplicação.
 *
 * Moram aqui, e não só no server block do nginx, porque assim viajam no git:
 * um deploy novo — ou uma VM nova — já sobe protegido, sem depender de alguém
 * lembrar de editar a configuração do servidor à mão. O nginx repete os mesmos
 * cabeçalhos (ver DEPLOY.md) para cobrir também os arquivos estáticos, que ele
 * entrega sem passar pelo PHP.
 */
class CabecalhosDeSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // O navegador respeita o Content-Type declarado em vez de adivinhar pelo
        // conteúdo do arquivo — fecha a porta de "subiram um .json que o
        // navegador resolveu executar como script".
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Ninguém embute o sistema dentro de um iframe. Sem isto, uma página
        // qualquer carrega a fila de notas invisível por cima de outra coisa e
        // colhe os cliques da pessoa (clickjacking).
        $response->headers->set('X-Frame-Options', 'DENY');

        // Ao clicar num link para fora, o navegador manda só a origem — nunca a
        // URL inteira com o filtro e o id da nota que estava aberta.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS: o navegador passa a recusar http:// neste domínio por um ano.
        // Só quando a requisição já veio por HTTPS — em desenvolvimento (http
        // local) o cabeçalho seria ignorado, e mandá-lo só polui a resposta.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
