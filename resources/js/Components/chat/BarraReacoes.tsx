import { RefObject, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Palette } from '@/lib/tema';
import Emoji from '@/Components/painel/Emoji';

/**
 * Os seis emojis de reação.
 *
 * Tem de bater EXATAMENTE com MensagemReacao::PERMITIDOS no servidor — é a
 * mesma lista, escrita nos dois lados. Se divergir, a tela oferece um emoji que
 * o servidor recusa, e o clique falha calado.
 *
 * Todos conferidos em public/emoji/. 😮 não entrou porque o SVG dele não existe
 * no pacote Noto que servimos; 😯 diz a mesma coisa e existe.
 */
export const REACOES = ['👍', '❤️', '😂', '😯', '😢', '🙏'];

/** Respiro entre a barra e a bolha, e entre a barra e a beirada da tela. */
const FOLGA = 6;
const MARGEM = 8;

/**
 * A fileira que aparece quando alguém vai reagir.
 *
 * ── Por que ela mora no <body>, e não ao lado da bolha ─────────────────────
 * A primeira versão era `absolute bottom-full` dentro da própria bolha. Parecia
 * certo e funcionava no meio da conversa — mas a área das mensagens é um
 * `overflow-y-auto`, e um filho `absolute` é RECORTADO pelo ancestral que rola.
 *
 * Na prática: reagindo à primeira mensagem visível, a barra ia para cima do
 * topo da área e sumia. Sobrava uma lasca do fundo escuro, sem emoji nenhum —
 * exatamente o que parecia "o balão bugou".
 *
 * Saindo num portal para o <body> com posição `fixed`, não há mais ancestral
 * que recorte: a barra é medida a partir da bolha e desenhada por cima de tudo.
 * De quebra, resolve qualquer disputa de empilhamento (z-index) com a barra
 * lateral.
 *
 * Ela se vira sozinha quando falta espaço: sem lugar em cima, abre embaixo; e
 * nunca passa da beirada da tela, o que importa no celular, onde a bolha pode
 * estar colada no canto.
 */
export default function BarraReacoes({ ancoraRef, gatilhoRef, minha, atual, onEscolher, onFechar, p }: {
    /** A bolha. É dela que a posição é medida. */
    ancoraRef: RefObject<HTMLElement>;
    /** O botão que abriu a barra — clicar nele de novo fecha, e não reabre. */
    gatilhoRef?: RefObject<HTMLElement>;
    /** Alinha pelo lado de quem falou: minhas bolhas puxam para a direita. */
    minha: boolean;
    /** O emoji que EU já pus nesta mensagem (para marcá-lo como aceso). */
    atual: string | null;
    onEscolher: (emoji: string) => void;
    onFechar: () => void;
    p: Palette;
}) {
    const caixaRef = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ top: number; left: number } | null>(null);

    /*
     * useLayoutEffect e não useEffect: a medição acontece ANTES de o navegador
     * pintar. Com o useEffect comum, a barra apareceria por um quadro no canto
     * (0,0) antes de pular para o lugar — e um quadro basta para ver o pulo.
     */
    useLayoutEffect(() => {
        const alvo  = ancoraRef.current;
        const caixa = caixaRef.current;

        if (!alvo || !caixa) return;

        const bolha = alvo.getBoundingClientRect();
        const barra = caixa.getBoundingClientRect();

        // Em cima da bolha. Sem espaço lá (mensagem no topo da rolagem), embaixo.
        let top = bolha.top - barra.height - FOLGA;
        if (top < MARGEM) top = bolha.bottom + FOLGA;

        // Alinhada pelo lado de quem falou, e presa dentro da tela.
        let left = minha ? bolha.right - barra.width : bolha.left;
        left = Math.max(MARGEM, Math.min(left, window.innerWidth - barra.width - MARGEM));

        setPos({ top, left });
    }, [ancoraRef, minha]);

    useEffect(() => {
        const abertaEm = Date.now();

        const fora = (e: MouseEvent) => {
            /*
             * Carência no celular.
             *
             * A barra abre com o dedo AINDA na tela (o toque longo dispara aos
             * 450ms). Quando o dedo levanta, o navegador dispara um mousedown
             * emulado na bolha — que é "fora" da barra e a fecharia no mesmo
             * instante em que ela apareceu. No computador isso não acontece, e
             * por isso não aparecia nos testes de mouse.
             */
            if (Date.now() - abertaEm < 500) return;

            const alvo = e.target as Node;

            // O gatilho se fecha sozinho (ele alterna). Sem esta linha, o
            // mousedown fecharia aqui e o click reabriria logo em seguida.
            if (caixaRef.current?.contains(alvo) || gatilhoRef?.current?.contains(alvo)) return;

            onFechar();
        };

        const tecla = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onFechar();
        };

        /*
         * Rolar ou redimensionar FECHA a barra, em vez de remedir.
         *
         * Posição fixa não acompanha a rolagem da conversa: a bolha desce e a
         * barra ficaria parada no ar, apontando para nada. Fechar é o que o
         * WhatsApp faz, e é mais honesto do que perseguir a bolha.
         *
         * `true` (fase de captura) porque quem rola é a área das mensagens, e
         * evento de rolagem de elemento não sobe até o document.
         */
        const sair = () => onFechar();

        // No próximo quadro: o clique que ABRIU a barra ainda está subindo pelo
        // DOM, e o ouvinte pegaria justamente ele.
        const id = requestAnimationFrame(() => {
            document.addEventListener('mousedown', fora);
            document.addEventListener('keydown', tecla);
            document.addEventListener('scroll', sair, true);
            window.addEventListener('resize', sair);
        });

        return () => {
            cancelAnimationFrame(id);
            document.removeEventListener('mousedown', fora);
            document.removeEventListener('keydown', tecla);
            document.removeEventListener('scroll', sair, true);
            window.removeEventListener('resize', sair);
        };
    }, [onFechar, gatilhoRef]);

    return createPortal(
        <div
            ref={caixaRef}
            className="flex items-center gap-0.5 px-1.5 py-1 rounded-full shadow-lg"
            style={{
                position: 'fixed',
                // Antes da medição ela já está no DOM (é assim que se mede a
                // altura dela), mas invisível — senão piscaria no canto.
                top: pos?.top ?? 0,
                left: pos?.left ?? 0,
                visibility: pos ? 'visible' : 'hidden',
                zIndex: 60,
                background: p.SURFACE,
                border: `1px solid ${p.BORDER}`,
            }}
        >
            {REACOES.map(emoji => {
                const aceso = atual === emoji;

                return (
                    <button
                        key={emoji}
                        type="button"
                        title={aceso ? 'Tirar a reação' : `Reagir com ${emoji}`}
                        onClick={() => onEscolher(emoji)}
                        className="p-1 rounded-full transition hover:scale-125"
                        style={{ background: aceso ? p.HOVER_ROW : 'transparent' }}
                    >
                        <Emoji emoji={emoji} size={20} />
                    </button>
                );
            })}
        </div>,
        document.body,
    );
}
