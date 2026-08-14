import { useEffect, useMemo, useRef, useState } from 'react';
import { Palette } from '@/lib/tema';
import { CATEGORIAS, buscar, guardarRecente, nomeDe, recentes } from '@/lib/emojisChat';
import Emoji from '@/Components/painel/Emoji';
import Icone from '@/Components/painel/Icone';

/**
 * O painel de emojis do chat — o equivalente ao Win+. dentro do sistema.
 *
 * Por que não é o Win+. de verdade: aquele é um recurso do Windows, e site
 * nenhum consegue abri-lo por conta própria. Ele também depende da máquina —
 * quem estiver num computador antigo tem outro conjunto, e quem usar o celular
 * não tem nada disso. Este painel é igual para todo mundo.
 *
 * O desenho vem do conjunto Noto (o emoji do Google), auto-hospedado em
 * public/emoji/ — o mesmo que os avatares já usam. Sem chamada a serviço de
 * terceiros: numa VM de 1 GB, e num galpão com wi-fi ruim, um seletor que
 * depende de rede externa é um seletor que às vezes não abre.
 */
export default function SeletorEmoji({ onEscolher, onFechar, p }: {
    onEscolher: (emoji: string) => void;
    onFechar: () => void;
    p: Palette;
}) {
    const [aba, setAba]       = useState<string>('recentes');
    const [termo, setTermo]   = useState('');
    const [usados, setUsados] = useState<string[]>(() => recentes());

    const caixaRef = useRef<HTMLDivElement>(null);

    /*
     * Fecha ao clicar fora e no Esc.
     *
     * O clique é ouvido no 'mousedown' e não no 'click': o botão que abriu o
     * painel também recebe o clique, e no 'click' a ordem faria fechar-e-abrir
     * de novo — o painel pareceria não fechar nunca.
     */
    useEffect(() => {
        const fora = (e: MouseEvent) => {
            if (!caixaRef.current?.contains(e.target as Node)) onFechar();
        };
        const tecla = (e: KeyboardEvent) => {
            if (e.key === 'Escape') { e.stopPropagation(); onFechar(); }
        };

        document.addEventListener('mousedown', fora);
        document.addEventListener('keydown', tecla);

        return () => {
            document.removeEventListener('mousedown', fora);
            document.removeEventListener('keydown', tecla);
        };
    }, [onFechar]);

    const resultado = useMemo(() => buscar(termo), [termo]);

    const escolher = (emoji: string) => {
        onEscolher(emoji);
        setUsados(guardarRecente(emoji));
    };

    // Buscando: a grade é o resultado. Senão, é a aba aberta.
    const grade: [string, string][] = termo.trim()
        ? resultado
        : aba === 'recentes'
            ? usados.map(e => [e, nomeDe(e)] as [string, string])
            : (CATEGORIAS.find(c => c.id === aba)?.emojis ?? []);

    return (
        <div
            ref={caixaRef}
            className="absolute bottom-full left-0 right-0 mb-1 rounded-xl shadow-2xl overflow-hidden z-30
                       flex flex-col"
            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}`, height: 296 }}
        >
            {/* ── Abas ── */}
            <div className="flex items-center gap-0.5 px-1.5 pt-1.5 shrink-0">
                <Aba
                    ativa={!termo && aba === 'recentes'}
                    titulo="Usados recentemente"
                    onClick={() => { setTermo(''); setAba('recentes'); }}
                    p={p}
                >
                    <Icone path="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" className="w-4 h-4" />
                </Aba>

                {CATEGORIAS.map(c => (
                    <Aba
                        key={c.id}
                        ativa={!termo && aba === c.id}
                        titulo={c.rotulo}
                        onClick={() => { setTermo(''); setAba(c.id); }}
                        p={p}
                    >
                        <Emoji emoji={c.icone} size={17} />
                    </Aba>
                ))}
            </div>

            {/* ── Busca ── */}
            <div className="px-2 py-1.5 shrink-0">
                <input
                    type="text"
                    value={termo}
                    onChange={e => setTermo(e.target.value)}
                    placeholder="Pesquisar emoji"
                    className="w-full rounded-lg text-xs px-2.5 py-1.5 outline-none"
                    style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
                />
            </div>

            {/* ── Grade ── */}
            <div className="flex-1 min-h-0 overflow-y-auto px-1.5 pb-1.5">
                {grade.length === 0 ? (
                    <p className="text-[11px] text-center py-8 px-3" style={{ color: p.MUTED }}>
                        {termo.trim()
                            ? 'Nenhum emoji com esse nome.'
                            : 'Os que você usar aparecem aqui.'}
                    </p>
                ) : (
                    <div className="grid grid-cols-8 gap-0.5">
                        {grade.map(([emoji, nome]) => (
                            <button
                                key={emoji}
                                type="button"
                                title={nome}
                                onClick={() => escolher(emoji)}
                                className="flex items-center justify-center h-8 rounded-md transition"
                                onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                            >
                                <Emoji emoji={emoji} size={21} />
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

/** Uma aba do topo — o traço embaixo marca a aberta, como no painel do Windows. */
function Aba({ ativa, titulo, onClick, children, p }: {
    ativa: boolean;
    titulo: string;
    onClick: () => void;
    children: React.ReactNode;
    p: Palette;
}) {
    return (
        <button
            type="button"
            title={titulo}
            onClick={onClick}
            className="flex-1 flex items-center justify-center h-7 rounded-md transition"
            style={{
                color: ativa ? p.ACCENT : p.MUTED,
                borderBottom: `2px solid ${ativa ? p.ACCENT : 'transparent'}`,
            }}
        >
            {children}
        </button>
    );
}
