import { useEffect, useRef } from 'react';
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

/**
 * A fileira que aparece por cima da bolha quando alguém vai reagir.
 *
 * Some sozinha ao clicar fora ou apertar Esc — é um menu, e menu que só fecha
 * pelo próprio botão vira estorvo no celular, onde não há "clicar fora" óbvio.
 */
export default function BarraReacoes({ minha, atual, onEscolher, onFechar, p }: {
    /** Alinha a fileira do lado certo: minhas bolhas ficam à direita. */
    minha: boolean;
    /** O emoji que EU já pus nesta mensagem (para marcá-lo como aceso). */
    atual: string | null;
    onEscolher: (emoji: string) => void;
    onFechar: () => void;
    p: Palette;
}) {
    const caixaRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const foraOuEsc = (e: MouseEvent | KeyboardEvent) => {
            if (e instanceof KeyboardEvent) {
                if (e.key === 'Escape') onFechar();
                return;
            }

            if (!caixaRef.current?.contains(e.target as Node)) onFechar();
        };

        /*
         * No próximo quadro, e não agora.
         *
         * O mesmo clique que abriu esta barra ainda está subindo pelo DOM. Se o
         * ouvinte entrasse neste instante, ele pegaria esse clique, veria que
         * aconteceu fora da caixa e fecharia a barra antes de ela aparecer.
         */
        const id = requestAnimationFrame(() => {
            document.addEventListener('mousedown', foraOuEsc);
            document.addEventListener('keydown', foraOuEsc);
        });

        return () => {
            cancelAnimationFrame(id);
            document.removeEventListener('mousedown', foraOuEsc);
            document.removeEventListener('keydown', foraOuEsc);
        };
    }, [onFechar]);

    return (
        <div
            ref={caixaRef}
            className={`absolute bottom-full mb-1 z-20 flex items-center gap-0.5 px-1.5 py-1
                        rounded-full shadow-lg ${minha ? 'right-0' : 'left-0'}`}
            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}
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
        </div>
    );
}
