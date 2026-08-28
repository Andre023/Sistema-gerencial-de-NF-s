<?php

namespace App\Services;

use App\Models\Ocorrencia;
use Illuminate\Support\Facades\Auth;

/**
 * Quem escreve no livro de ocorrências.
 *
 * ── O desenho, em duas camadas ────────────────────────────────────────────────
 *
 * A primeira é automática: os observers (App\Observers) escutam os modelos e
 * registram sozinhos. É a rede de segurança — o dia em que alguém criar uma rota
 * nova que edita a observação, ela já nasce registrada sem ninguém lembrar disso.
 * Espalhar chamadas por oito controllers apodrece exatamente aí.
 *
 * A segunda é a INTENÇÃO, e existe porque o observer vê o efeito, não o motivo.
 * Ele sabe que `liberada_em` virou nulo; não sabe que isso se chama "devolveu ao
 * recebimento" — e "liberada_em: 12/08 → vazio" não é frase que se leia numa
 * tela. Então o controller avisa o nome do ato antes de agir:
 *
 *     Ocorrencias::intencao(Ocorrencia::NOTA_DEVOLVIDA);
 *     $nota->update([...]);
 *
 * A intenção vale para a PRÓXIMA gravação da nota e some ao ser usada. Curta de
 * propósito: intenção que sobra gruda no próximo update e mente.
 */
class Ocorrencias
{
    /** O nome do ato que está em curso, à espera do update que o realiza. */
    private static ?string $intencao = null;

    /** Contexto extra do ato (o motivo do cancelamento, a fila de destino). */
    private static ?array $contexto = null;

    /**
     * Declara o que a próxima gravação da nota significa.
     *
     * Só para os atos do ciclo de vida (liberar, cancelar, devolver...). Edição
     * de campo não precisa: ali o próprio antes/depois já se explica.
     */
    public static function intencao(string $acao, ?array $contexto = null): void
    {
        self::$intencao = $acao;
        self::$contexto = $contexto;
    }

    /** Pega a intenção e a apaga — ela vale uma vez só. */
    public static function consumirIntencao(): ?array
    {
        if (self::$intencao === null) {
            return null;
        }

        $consumida = ['acao' => self::$intencao, 'contexto' => self::$contexto];

        self::$intencao = null;
        self::$contexto = null;

        return $consumida;
    }

    /**
     * Esquece a intenção pendente.
     *
     * Chamado quando a ação NÃO chegou a acontecer (validação barrou, o card não
     * podia ser resolvido). Sem isto a intenção órfã ficaria esperando e
     * carimbaria o próximo update, que é de outra coisa.
     */
    public static function limparIntencao(): void
    {
        self::$intencao = null;
        self::$contexto = null;
    }

    /**
     * Escreve a linha.
     *
     * `user_id` nulo é o sistema agindo sozinho — o job que limpa anexos
     * vencidos roda sem ninguém logado, e some do log se exigirmos um autor.
     */
    public static function registrar(int $notaId, string $acao, ?array $dados = null): void
    {
        Ocorrencia::create([
            'nota_id' => $notaId,
            'user_id' => Auth::id(),
            'acao'    => $acao,
            'dados'   => $dados ?: null,
        ]);
    }

    /**
     * O antes e o depois dos campos que interessam.
     *
     * Devolve [] quando a gravação não tocou em nenhum campo observado — é o que
     * mantém fora do log o `visualizando_por` do 🙋‍♂️, que muda a cada clique e
     * afogaria as ocorrências de verdade.
     *
     * @param  array<string,mixed>  $mudancas  o que foi gravado (getChanges)
     * @param  array<string,mixed>  $antes     como estava (getOriginal)
     */
    public static function diff(array $mudancas, array $antes): array
    {
        $campos = [];

        foreach (Ocorrencia::camposObservados() as $campo) {
            if (! array_key_exists($campo, $mudancas)) {
                continue;
            }

            $de   = $antes[$campo] ?? null;
            $para = $mudancas[$campo];

            // O Eloquent considera mudança o que o banco aceitou gravar; '' e
            // null saem diferentes daqui e são a mesma ausência para quem lê.
            if ((string) $de === (string) $para) {
                continue;
            }

            $campos[$campo] = ['de' => $de, 'para' => $para];
        }

        return $campos;
    }
}
