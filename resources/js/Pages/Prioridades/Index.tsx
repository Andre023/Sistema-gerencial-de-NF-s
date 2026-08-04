import { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Fornecedor } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';

interface Props {
    prioritarios: Fornecedor[];
    resultados: Fornecedor[];
    busca: string;
}

function Estrela({ ativa, p }: { ativa: boolean; p: Palette }) {
    return (
        <span style={{ color: ativa ? p.AMBER : p.MUTED, fontSize: 18, lineHeight: 1 }}>
            {ativa ? '★' : '☆'}
        </span>
    );
}

export default function Index({ prioritarios, resultados, busca }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const [termo, setTermo] = useState(busca);
    const primeiro = useRef(true);

    // Busca debounced: atualiza a URL (?busca=) que o controller lê e re-renderiza
    useEffect(() => {
        if (primeiro.current) { primeiro.current = false; return; }
        const t = setTimeout(() => {
            router.get(route('prioridades.index'), termo ? { busca: termo } : {}, {
                preserveState: true, preserveScroll: true, replace: true,
            });
        }, 350);
        return () => clearTimeout(t);
    }, [termo]);

    const alternar = (f: Fornecedor) => {
        router.patch(route('prioridades.alternar', f.id), { prioridade: !f.prioridade }, {
            preserveState: true, preserveScroll: true,
        });
    };

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Prioridades" />

            <div className="flex-1 w-full py-6 px-4 sm:px-6 lg:px-8 max-w-3xl mx-auto space-y-5 transition-colors duration-200"
                style={{ background: p.BG }}>

                <div>
                    <h1 className="text-lg font-semibold" style={{ color: p.TEXT }}>Fornecedores prioritários</h1>
                    <p className="text-sm mt-1" style={{ color: p.MUTED }}>
                        Quando uma nota destes fornecedores entra no pré-lote, ela vai direto para o
                        <span style={{ color: p.AMBER }}> topo </span>da fila.
                    </p>
                </div>

                {/* Busca / adicionar */}
                <div className="rounded-xl p-4 space-y-3" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <label className="block text-sm font-medium" style={{ color: p.MUTED }}>Adicionar fornecedor</label>
                    <input value={termo} onChange={e => setTermo(e.target.value)}
                        placeholder="Buscar por nome..."
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                        style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />

                    {termo.trim() !== '' && (
                        <div>
                            {resultados.length === 0
                                ? <p className="text-sm py-2" style={{ color: p.MUTED }}>Nenhum fornecedor encontrado.</p>
                                : resultados.map(f => (
                                    <button key={f.id} onClick={() => alternar(f)}
                                        className="w-full flex items-center gap-2.5 py-2 px-1 text-left rounded-lg transition"
                                        onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                        <Estrela ativa={!!f.prioridade} p={p} />
                                        <span className="text-sm truncate" style={{ color: p.TEXT }}>{f.nome}</span>
                                        {f.prioridade && (
                                            <span className="ml-auto text-xs" style={{ color: p.AMBER }}>prioritário</span>
                                        )}
                                    </button>
                                ))}
                        </div>
                    )}
                </div>

                {/* Marcados */}
                <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="px-4 py-3 text-sm font-semibold"
                        style={{ color: p.TEXT, borderBottom: `1px solid ${p.BORDER}` }}>
                        Prioritários ({prioritarios.length})
                    </div>
                    {prioritarios.length === 0
                        ? <p className="text-sm px-4 py-8 text-center" style={{ color: p.MUTED }}>
                            Nenhum fornecedor prioritário ainda. Busque acima e clique na estrela.
                          </p>
                        : prioritarios.map(f => (
                            <div key={f.id} className="flex items-center justify-between px-4 py-2.5 group"
                                style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                <div className="flex items-center gap-2.5 min-w-0">
                                    <span style={{ color: p.AMBER, fontSize: 18, lineHeight: 1 }}>★</span>
                                    <span className="text-sm truncate" style={{ color: p.TEXT }}>{f.nome}</span>
                                </div>
                                <button onClick={() => alternar(f)}
                                    className="text-xs px-3 py-2 rounded-lg transition acoes-hover"
                                    style={{ color: p.RED }}
                                    onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                    Remover
                                </button>
                            </div>
                        ))}
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
