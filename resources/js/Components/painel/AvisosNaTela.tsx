import { useEffect, useRef, useState } from 'react';
import { Notificacao } from '@/types';
import { NOTIFICACAO_LABEL, notificacaoCor, usePaleta, lojaNome } from '@/lib/tema';
import { resumoTipos } from './NotificacoesProvider';

/** Um card vivo na tela. A chave inclui o updated_at: a mesma nota avisando de
 *  novo (outra divergência) é um card diferente, não uma repetição. */
export interface AvisoNaTela {
    chave: string;
    notificacao: Notificacao;
}

/** Tempo que o card fica na tela antes de sumir sozinho */
const DURACAO = 9000;

interface Props {
    avisos: AvisoNaTela[];
    onFechar: (chave: string) => void;
    onAbrir: (n: Notificacao) => void;
}

/**
 * Pilha de avisos no canto inferior direito, no estilo do WhatsApp: entra
 * deslizando pela direita, o mais recente fica por cima e some sozinho depois
 * de alguns segundos — a menos que o mouse esteja em cima dele.
 */
export default function AvisosNaTela({ avisos, onFechar, onAbrir }: Props) {
    if (!avisos.length) return null;

    return (
        <div
            className="fixed bottom-4 right-4 z-[60] flex flex-col gap-2 w-[22rem] max-w-[calc(100vw-2rem)] pointer-events-none"
            aria-live="polite"
        >
            {avisos.map(a => (
                <Card
                    key={a.chave}
                    n={a.notificacao}
                    onFechar={() => onFechar(a.chave)}
                    onAbrir={() => { onFechar(a.chave); onAbrir(a.notificacao); }}
                />
            ))}
        </div>
    );
}

function Card({ n, onFechar, onAbrir }: { n: Notificacao; onFechar: () => void; onAbrir: () => void }) {
    const { isDark, p } = usePaleta();
    const cor = notificacaoCor(n.tipo, p);

    // Entra deslizando: o primeiro quadro nasce deslocado, o seguinte assenta.
    const [dentro, setDentro] = useState(false);
    useEffect(() => {
        const id = requestAnimationFrame(() => setDentro(true));
        return () => cancelAnimationFrame(id);
    }, []);

    // Some sozinho, mas o relógio para enquanto o mouse está em cima
    const timer = useRef<number | null>(null);

    const parar = () => {
        if (timer.current !== null) { clearTimeout(timer.current); timer.current = null; }
    };

    const contar = () => {
        parar();
        timer.current = window.setTimeout(onFechar, DURACAO);
    };

    useEffect(() => {
        contar();
        return parar;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={onAbrir}
            onKeyDown={e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onAbrir(); } }}
            onMouseEnter={parar}
            onMouseLeave={contar}
            className={`pointer-events-auto cursor-pointer rounded-lg overflow-hidden text-left transition-all duration-300 ease-out ${
                dentro ? 'translate-x-0 opacity-100' : 'translate-x-6 opacity-0'
            }`}
            style={{
                background: p.SURFACE,
                border: `1px solid ${p.BORDER}`,
                borderLeft: `4px solid ${cor}`,
                boxShadow: isDark
                    ? '0 10px 30px rgba(0,0,0,0.55)'
                    : '0 10px 30px rgba(0,0,0,0.18)',
            }}
        >
            <div className="flex items-start gap-2 px-4 py-3">
                <div className="min-w-0 flex-1">
                    <div className="flex items-baseline gap-2">
                        <span className="text-sm font-semibold truncate" style={{ color: p.TEXT }}>
                            {n.fornecedor ?? 'Nota fiscal'}
                        </span>
                    </div>

                    <div className="text-xs mt-0.5" style={{ color: p.MUTED }}>
                        NF {n.numero_nota} {n.loja != null && `· ${lojaNome(n.loja)}`}
                    </div>

                    <div className="text-xs font-medium mt-1" style={{ color: cor }}>
                        {resumoTipos(n)}
                    </div>

                    {/* Quando há tipos, o rótulo do salto vira a legenda embaixo */}
                    {n.tipos.length > 0 && (
                        <div className="text-[11px] mt-0.5" style={{ color: p.MUTED }}>
                            {NOTIFICACAO_LABEL[n.tipo]}
                            {n.autor && ` · ${n.autor}`}
                        </div>
                    )}
                </div>

                <button
                    type="button"
                    aria-label="Dispensar aviso"
                    onClick={e => { e.stopPropagation(); onFechar(); }}
                    className="shrink-0 -mr-1 -mt-1 p-1 rounded transition hover:opacity-100 opacity-50"
                    style={{ color: p.MUTED }}
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    );
}
