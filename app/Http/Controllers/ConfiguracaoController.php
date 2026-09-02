<?php

namespace App\Http\Controllers;

use App\Models\CampanhaFornecedor;
use App\Models\Configuracao;
use App\Models\Fornecedor;
use App\Support\CartaCampanha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurações — o painel do admin.
 *
 * A tela tem um seletor à esquerda e as seções à direita: Usuários (que saiu da
 * navbar para abrir espaço) e Campanha de aniversário. Cada seção é uma página
 * Inertia própria; o que dá a aparência de aba é o layout compartilhado
 * (resources/js/Pages/Configuracoes/Secoes.tsx).
 */
class ConfiguracaoController extends Controller
{
    public function campanha(): Response
    {
        return Inertia::render('Configuracoes/Campanha', [
            'ativa'          => Configuracao::campanhaAtiva(),
            'textoPadrao'    => Configuracao::campanhaTextoPadrao(),
            'textoDeFabrica' => CartaCampanha::TEXTO_DE_FABRICA,
            'limiteDeCaracteres' => CartaCampanha::LIMITE_DE_CARACTERES,
        ]);
    }

    /**
     * Liga/desliga a aba e guarda o texto padrão da loja.
     *
     * Desligada, a aba some do menu de todo mundo — inclusive do admin, que
     * continua chegando aqui por Configurações para religar.
     */
    public function atualizarCampanha(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'ativa'        => ['required', 'boolean'],
            'texto_padrao' => ['required', 'string', 'max:' . CartaCampanha::LIMITE_DE_CARACTERES],
        ]);

        Configuracao::definirCampanhaAtiva($dados['ativa']);
        Configuracao::definir(Configuracao::CAMPANHA_TEXTO_PADRAO, $dados['texto_padrao']);

        return redirect()->route('configuracoes.campanha')->with('sucesso', $dados['ativa']
            ? 'Campanha ativa — a aba está no menu de compras.'
            : 'Campanha desativada — a aba saiu do menu.');
    }

    // ─── FORNECEDORES ─────────────────────────────────────────────────────────
    //
    // São DUAS listas, e elas não se misturam:
    //
    //   • notas    — os que aparecem ao lançar nota. Vêm de importação e de
    //                cadastro na hora, então acumulam erro de digitação. Corrigir
    //                aqui conserta o histórico inteiro: a nota aponta para o id,
    //                não para o texto.
    //   • campanha — os da planilha de compras, com faturamento. Corrigir aqui
    //                dura até o próximo envio de planilha, que TROCA a tabela
    //                inteira. A tela avisa isso; o valor está em arrumar um nome
    //                agora, não em manter.
    //
    // Quantos são: ~2.800 de notas. Por isso a busca é do servidor e devolve no
    // máximo LIMITE_BUSCA — mandar a lista toda para a tela seria repetir o erro
    // que custava 136 KB por ação na fila.

    /** Teto de linhas que a busca devolve. */
    private const LIMITE_BUSCA = 60;

    public function fornecedores(): Response
    {
        return Inertia::render('Configuracoes/Fornecedores', [
            'totalNotas'    => Fornecedor::count(),
            'totalCampanha' => CampanhaFornecedor::count(),
        ]);
    }

    /** Busca por nome, nas duas listas. */
    public function buscarFornecedores(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'q'    => ['nullable', 'string', 'max:120'],
            'tipo' => ['required', 'in:notas,campanha'],
        ]);

        $termo = trim($dados['q'] ?? '');

        if ($dados['tipo'] === 'campanha') {
            $lista = CampanhaFornecedor::query()
                ->when($termo !== '', fn($q) => $q->where('nome', 'like', "%{$termo}%"))
                ->orderBy('nome')
                ->limit(self::LIMITE_BUSCA)
                ->get(['id', 'nome', 'faturamento'])
                ->map(fn($f) => [
                    'id'          => $f->id,
                    'nome'        => $f->nome,
                    'faturamento' => $f->faturamento === null ? null : (float) $f->faturamento,
                    'notas'       => null,
                ]);
        } else {
            $lista = Fornecedor::query()
                ->when($termo !== '', fn($q) => $q->where('nome', 'like', "%{$termo}%"))
                // Quantas notas dependem dele: é o que diz o tamanho do estrago
                // de um nome errado, e o que separa o duplicado real do parecido.
                ->withCount('notas')
                ->orderBy('nome')
                ->limit(self::LIMITE_BUSCA)
                ->get(['id', 'nome'])
                ->map(fn($f) => [
                    'id'          => $f->id,
                    'nome'        => $f->nome,
                    'faturamento' => null,
                    'notas'       => $f->notas_count,
                ]);
        }

        return response()->json([
            'fornecedores' => $lista,
            // A tela precisa saber que a lista foi cortada, senão quem procura
            // um nome comum acha que ele não existe.
            'truncada'     => $lista->count() === self::LIMITE_BUSCA,
        ]);
    }

    /**
     * Renomeia um fornecedor das NOTAS.
     *
     * `nome` é único na tabela. Renomear para um nome que já existe é, na
     * prática, um pedido de FUSÃO — e fundir é repontar todas as notas de um
     * para o outro e apagar o que sobra. Isso mexe em histórico e não é o que
     * "editar o nome" promete, então aqui a colisão é recusada com o número de
     * notas dos dois lados: é a informação que falta para decidir se vale fundir.
     */
    public function renomearFornecedor(Request $request, Fornecedor $fornecedor): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
        ]);

        $nome = trim(preg_replace('/\s+/', ' ', $dados['nome']) ?? $dados['nome']);

        if ($nome === '') {
            return response()->json(['erro' => 'O nome não pode ficar vazio.'], 422);
        }

        $colide = Fornecedor::where('nome', $nome)->whereKeyNot($fornecedor->id)->withCount('notas')->first();

        if ($colide) {
            return response()->json([
                'erro' => sprintf(
                    'Já existe um fornecedor com esse nome (%d nota%s). Este tem %d. '
                    . 'Juntar os dois é uma fusão, que mexe no histórico — peça se for isso que você quer.',
                    $colide->notas_count,
                    $colide->notas_count === 1 ? '' : 's',
                    $fornecedor->notas()->count(),
                ),
            ], 422);
        }

        $fornecedor->update(['nome' => $nome]);

        return response()->json(['fornecedor' => [
            'id'          => $fornecedor->id,
            'nome'        => $fornecedor->nome,
            'faturamento' => null,
            'notas'       => $fornecedor->notas()->count(),
        ]]);
    }

    /**
     * Renomeia um fornecedor da CAMPANHA.
     *
     * A `chave` é recalculada junto: é ela que reconhece o fornecedor quando o
     * comprador digita o nome em vez de escolher na lista, e um nome novo com a
     * chave velha deixaria de casar exatamente com o que se acabou de escrever.
     */
    public function renomearFornecedorCampanha(Request $request, CampanhaFornecedor $campanhaFornecedor): JsonResponse
    {
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
        ]);

        $nome = trim(preg_replace('/\s+/', ' ', $dados['nome']) ?? $dados['nome']);

        if ($nome === '') {
            return response()->json(['erro' => 'O nome não pode ficar vazio.'], 422);
        }

        $campanhaFornecedor->update([
            'nome'  => $nome,
            'chave' => CampanhaFornecedor::chaveDe($nome),
        ]);

        return response()->json(['fornecedor' => [
            'id'          => $campanhaFornecedor->id,
            'nome'        => $campanhaFornecedor->nome,
            'faturamento' => $campanhaFornecedor->faturamento === null
                ? null : (float) $campanhaFornecedor->faturamento,
            'notas'       => null,
        ]]);
    }

    /**
     * Apaga um fornecedor das NOTAS — só se ninguém depender dele.
     *
     * O vínculo `notas.fornecedor_id` é `restrictOnDelete`: o banco recusaria de
     * qualquer forma, com um erro de integridade que a tela não sabe explicar.
     * Conferir antes troca isso por uma frase que diz o que aconteceu e por quê.
     *
     * E a trava é a certa: apagar um fornecedor com nota deixaria o histórico
     * apontando para o vazio. O que se limpa aqui é o cadastro duplicado que
     * nunca chegou a ser usado — que é justamente o lixo que sobra de importação
     * e de nome digitado errado.
     */
    public function excluirFornecedor(Fornecedor $fornecedor): JsonResponse
    {
        $notas = $fornecedor->notas()->count();

        if ($notas > 0) {
            return response()->json([
                'erro' => sprintf(
                    'Este fornecedor tem %d nota%s e não pode ser apagado — o histórico ficaria '
                    . 'apontando para o vazio. Se ele é duplicado, corrija o nome do outro.',
                    $notas,
                    $notas === 1 ? '' : 's',
                ),
            ], 422);
        }

        $fornecedor->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Apaga um fornecedor da CAMPANHA.
     *
     * Aqui não há o que travar: nada aponta para esta tabela, e a lista de
     * atendidos guarda cópia própria do nome e dos valores justamente para não
     * depender dela. Some da busca e da sugestão de faturamento, e volta no
     * próximo envio de planilha se ainda estiver lá.
     */
    public function excluirFornecedorCampanha(CampanhaFornecedor $campanhaFornecedor): JsonResponse
    {
        $campanhaFornecedor->delete();

        return response()->json(['ok' => true]);
    }
}
