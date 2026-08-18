import { router } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';

interface Atalho { id: string; rotulo: string }

/**
 * Atalhos para as planilhas da página de notas.
 *
 * A página ficou longa: recebimento, pré-lote, devoluções, liberadas e
 * canceladas, uma embaixo da outra. Chegar nas canceladas era rolar a fila
 * inteira — e quem queria só conferir uma devolução passava por tudo.
 *
 * Ficam numa faixa PRÓPRIA, colada embaixo da navbar, e não misturados aos
 * links dela. São coisas diferentes: os de cima levam a outra página, estes
 * andam dentro desta. Juntos, os cinco também espremeriam a navbar — que já
 * carrega cinco abas, e-mail e usuário — justamente nas telas de 1024px do
 * galpão.
 */
export default function AtalhosDaFila({ podeVerDevolucoes }: { podeVerDevolucoes: boolean }) {
    const { isDark } = useTheme();

    const atalhos: Atalho[] = [
        { id: 'secao-recebimento', rotulo: 'Recebimento' },
        { id: 'secao-pre-lote',    rotulo: 'Pré-lote' },
        ...(podeVerDevolucoes ? [{ id: 'secao-devolucoes', rotulo: 'Devoluções' }] : []),
        { id: 'secao-liberadas',   rotulo: 'Liberadas' },
        { id: 'secao-canceladas',  rotulo: 'Canceladas' },
    ];

    /**
     * Rola até a seção.
     *
     * Tenta suave e CONFERE se saiu do lugar. Nem todo navegador honra o
     * `behavior: 'smooth'` — no navegador embutido daqui ele simplesmente não
     * faz nada, e o atalho ficava mudo: a pessoa clicava e a tela não mexia.
     * Quem desliga animação no sistema ("reduzir movimento") cai no mesmo caso,
     * e de propósito.
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

    const fundo = isDark ? 'bg-[#0d1117]' : 'bg-gray-50';
    const borda = isDark ? 'border-[#21262d]' : 'border-gray-200';
    const texto = isDark
        ? 'text-[#7d8590] hover:text-[#e6edf3] hover:bg-[#21262d]'
        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-200';

    return (
        <div className={`${fundo} border-b ${borda} sticky top-16 z-30`}>
            {/* Rola para o lado no celular em vez de quebrar em duas linhas */}
            <div className="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">
                <div className="flex items-center gap-1 py-1.5 overflow-x-auto rolagem-x">
                    <span className={`text-[11px] uppercase tracking-wider shrink-0 pr-1 ${isDark ? 'text-[#484f58]' : 'text-gray-400'}`}>
                        Ir para
                    </span>
                    {atalhos.map(a => (
                        <button key={a.id} onClick={() => ir(a.id)}
                            className={`shrink-0 text-xs font-medium px-2.5 py-1 rounded-md transition ${texto}`}>
                            {a.rotulo}
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}
