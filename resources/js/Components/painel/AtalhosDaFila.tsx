import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';

interface Atalho { id: string; rotulo: string }

/** Chave no navegador: recolhido ou não é gosto de cada pessoa, não da conta. */
const CHAVE = 'nfs:atalhos-recolhidos';

const lerRecolhido = (): boolean => {
    try { return localStorage.getItem(CHAVE) === '1'; } catch { return false; }
};

const guardarRecolhido = (v: boolean) => {
    try { localStorage.setItem(CHAVE, v ? '1' : '0'); } catch { /* navegador sem storage: só não lembra */ }
};

/**
 * Atalhos para as planilhas da página de notas, dentro da navbar.
 *
 * A página ficou longa: recebimento, pré-lote, devoluções, liberadas e
 * canceladas, uma embaixo da outra. Chegar nas canceladas era rolar a fila
 * inteira.
 *
 * Ficam SEMPRE visíveis, e não só na fila: de outra página eles navegam até lá
 * e rolam sozinhos. Aparecer e sumir conforme a página faria a navbar mudar de
 * largura a cada clique, e os links de cima dançariam de lugar.
 *
 * Mas dá para RECOLHER: são cinco botões, e quem não usa (ou usa tela
 * estreita) fica com o resto da navbar espremido. A setinha na ponta esconde
 * os cinco e deixa só ela, no mesmo lugar, para trazê-los de volta. A escolha
 * fica no navegador — é preferência da pessoa, não da conta.
 *
 * O desenho é de propósito mais leve que o dos NavLink ao lado: são âncoras
 * dentro de uma página, não destinos diferentes. Misturá-los com o mesmo peso
 * faria "Liberadas" parecer uma tela, que não é.
 */
export default function AtalhosDaFila({ podeVerDevolucoes }: { podeVerDevolucoes: boolean }) {
    const { isDark } = useTheme();
    const [recolhido, setRecolhido] = useState(lerRecolhido);

    const alternar = () => {
        const novo = !recolhido;
        setRecolhido(novo);
        guardarRecolhido(novo);
    };

    const atalhos: Atalho[] = [
        { id: 'secao-recebimento', rotulo: 'Recebimento' },
        // A ordem espelha a da página de propósito. São âncoras, não telas —
        // listá-las fora de ordem faria a tela subir onde a barra sugeria descer.
        ...(podeVerDevolucoes ? [{ id: 'secao-devolucoes', rotulo: 'Devoluções' }] : []),
        { id: 'secao-pre-lote',    rotulo: 'Pré-lote' },
        { id: 'secao-liberadas',   rotulo: 'Liberadas' },
        { id: 'secao-canceladas',  rotulo: 'Canceladas' },
    ];

    /**
     * Rola até a seção.
     *
     * Tenta suave e CONFERE se saiu do lugar. Nem todo navegador honra o
     * `behavior: 'smooth'` — onde ele não pega, o atalho ficava mudo: a pessoa
     * clicava e a tela não mexia. Quem desliga animação no sistema ("reduzir
     * movimento") cai no mesmo caso, e de propósito.
     */
    const rolarAte = (alvo: HTMLElement) => {
        const antes = window.scrollY;

        alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // 250ms é tempo de a animação ter começado, não de terminar: basta ter
        // saído do lugar para sabermos que o 'smooth' pegou.
        setTimeout(() => {
            if (Math.abs(window.scrollY - antes) < 2) {
                alvo.scrollIntoView({ block: 'start' });
            }
        }, 250);
    };

    /** Se a seção não existe, a pessoa está noutra página: vai para a fila primeiro. */
    const ir = (id: string) => {
        const alvo = document.getElementById(id);

        if (alvo) {
            rolarAte(alvo);
            return;
        }

        router.visit(route('notas.index'), {
            onSuccess: () => {
                // Um quadro depois: o Inertia troca os dados antes de o React
                // desenhar, e sem esperar o alvo ainda não está no documento.
                requestAnimationFrame(() => {
                    const chegou = document.getElementById(id);
                    if (chegou) rolarAte(chegou);
                });
            },
        });
    };

    const cor = isDark
        ? 'text-[#7d8590] hover:text-[#e6edf3] hover:bg-[#21262d]'
        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100';

    return (
        <>
            {!recolhido && atalhos.map(a => (
                <button key={a.id} onClick={() => ir(a.id)}
                    title={`Ir para ${a.rotulo}`}
                    className={`shrink-0 text-xs font-medium px-2 py-1 rounded-md transition ${cor}`}>
                    {a.rotulo}
                </button>
            ))}

            {/* A setinha: aponta para dentro quando os atalhos estão abertos
                (recolher) e para fora quando estão escondidos (expandir). */}
            <button type="button" onClick={alternar}
                title={recolhido ? 'Mostrar atalhos das planilhas' : 'Recolher atalhos das planilhas'}
                aria-label={recolhido ? 'Mostrar atalhos das planilhas' : 'Recolher atalhos das planilhas'}
                aria-expanded={!recolhido}
                className={`shrink-0 flex items-center justify-center w-6 h-6 rounded-md transition ${cor}`}>
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    {recolhido
                        ? <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                        : <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />}
                </svg>
            </button>
        </>
    );
}
