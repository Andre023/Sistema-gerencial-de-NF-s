<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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
        // O nonce tem de existir ANTES da tela ser montada: é ele que autoriza
        // os <script> da página. O @vite carimba as tags dele sozinho; o
        // @routes (Ziggy) recebe o nonce à mão no app.blade.php.
        $nonce = Vite::useCspNonce();

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

        // O sistema não usa câmera, microfone nem localização. Declarar isso
        // impede que um script carregado por engano peça esses acessos em nome
        // do site — o navegador nega antes de perguntar ao usuário.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), midi=(), magnetometer=()',
        );

        // Janela aberta por window.open não fica com referência de volta para
        // esta — corta o "tabnabbing", em que a página aberta reescreve a origem.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        $response->headers->set('Content-Security-Policy', $this->politica($nonce));

        // HSTS: o navegador passa a recusar http:// neste domínio por um ano.
        // Só quando a requisição já veio por HTTPS — em desenvolvimento (http
        // local) o cabeçalho seria ignorado, e mandá-lo só polui a resposta.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Content-Security-Policy — a trava que sobra caso algum dia entre HTML de
     * fora na tela. Sem ela, um texto com <script> gravado num comentário ou no
     * nome de um fornecedor roda com a sessão de quem abrir a nota.
     *
     * O ponto que faz o trabalho é o script-src: só roda script servido pelo
     * próprio domínio ou carimbado com o nonce desta resposta — e o nonce muda a
     * cada carregamento, então não há como um HTML injetado adivinhá-lo.
     *
     * Duas frouxidões conscientes, para não derrubar o que já funciona:
     *
     *   • style-src 'unsafe-inline' — as telas montam cor e espaçamento em
     *     atributo de estilo (o tema claro/escuro vive disso). CSS injetado é
     *     estrago muito menor que script, e o script continua trancado.
     *   • connect-src ws:/wss: — o tempo real (Reverb) abre WebSocket para um
     *     host que só o .env do servidor conhece. Prender o host aqui e errá-lo
     *     mataria a atualização automática da tela sem aviso.
     */
    private function politica(string $nonce): string
    {
        $diretivas = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data: blob:",
            "connect-src 'self' ws: wss:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            // Onde os formulários podem postar e qual <base href> vale: fecha o
            // desvio de um POST de login para fora do domínio.
            "form-action 'self'",
            "base-uri 'self'",
            // Repete o X-Frame-Options no padrão atual (e cobre navegador que
            // já não olha o cabeçalho antigo).
            "frame-ancestors 'none'",
            "object-src 'none'",
        ];

        // `npm run dev`: as telas vêm do servidor do Vite, em outra porta, e o
        // recarregamento automático fala por WebSocket com ele. Sem abrir essa
        // origem, desenvolver com a CSP ligada seria impossível — e uma CSP que
        // só existe em produção é uma CSP que ninguém testa antes do deploy.
        if (Vite::isRunningHot() && ($dev = $this->origemDoVite())) {
            $diretivas[0] .= " {$dev}";                    // default-src
            $diretivas[1] .= " {$dev}";                    // script-src
        }

        return implode('; ', $diretivas);
    }

    /** Origem do servidor de desenvolvimento do Vite (ex.: http://localhost:5173). */
    private function origemDoVite(): ?string
    {
        $hot = public_path('hot');

        if (! is_file($hot)) {
            return null;
        }

        $url = trim((string) file_get_contents($hot));
        $partes = parse_url($url);

        if (empty($partes['scheme']) || empty($partes['host'])) {
            return null;
        }

        return $partes['scheme'] . '://' . $partes['host']
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
    }
}
