import { useState, useEffect, useRef } from 'react';
import Secoes, { Cartao } from '@/Components/configuracoes/Secoes';
import { Head, router, usePage } from '@inertiajs/react';
import { Fornecedor, Permissoes } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';
import { SeloConsignado } from '@/Components/painel/SeloConsignado';

interface Props {
    consignados: Fornecedor[];
    resultados: Fornecedor[];
    busca: string;
}

/**
 * Configurações › Consignados.
 *
 * O mesmo desenho de Prioridades: os já marcados em lista, e uma busca para
 * achar quem falta. A diferença é o que a marca faz — aqui ela não muda a
 * ordem da fila; ela põe um selo antes do nome em toda nota do fornecedor.
 *
 * O visitante entra e vê a lista, mas não tem botão: é só-leitura, como no
 * resto do sistema. A busca fica escondida para ele porque só serve para marcar.
 */
export default function Consignados({ consignados, resultados, busca }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const { can } = (usePage().props as { auth: { can: Permissoes } }).auth;
    const podeMarcar = can.marcarConsignados;

    const [termo, setTermo] = useState(busca);
    const primeiro = useRef(true);

    // Busca debounced: atualiza a URL (?busca=) que o controller lê e re-renderiza
    useEffect(() => {
        if (primeiro.current) { primeiro.current = false; return; }
        const t = setTimeout(() => {
            router.get(route('configuracoes.consignados'), termo ? { busca: termo } : {}, {
                preserveState: true, preserveScroll: true, replace: true,
            });
        }, 350);
        return () => clearTimeout(t);
    }, [termo]);

    const alternar = (f: Fornecedor) => {
        if (!podeMarcar) return;
        router.patch(route('configuracoes.consignados.alternar', f.id), { consignado: !f.consignado }, {
            preserveState: true, preserveScroll: true,
        });
    };

    return (
        <Secoes atual="consignados">
            <Head title="Consignados" />

            <div className="space-y-5">
                <Cartao p={p} titulo="Fornecedores consignados"
                    descricao="Toda nota destes fornecedores ganha o selo antes do nome na fila — automaticamente, sem marcar nada ao lançar. Diferente do CEASA, que é da nota, o consignado é do fornecedor.">
                    {podeMarcar ? (
                        <div className="space-y-3">
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
                                                <Marca ativa={!!f.consignado} p={p} />
                                                <span className="text-sm truncate" style={{ color: p.TEXT }}>{f.nome}</span>
                                                {f.consignado && (
                                                    <span className="ml-auto text-xs" style={{ color: p.ORANGE }}>consignado</span>
                                                )}
                                            </button>
                                        ))}
                                </div>
                            )}
                        </div>
                    ) : (
                        <p className="text-sm" style={{ color: p.MUTED }}>
                            Sua conta é só de consulta: você vê a lista, mas quem marca é quem opera a fila.
                        </p>
                    )}
                </Cartao>

                {/* Marcados */}
                <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="px-4 py-3 text-sm font-semibold"
                        style={{ color: p.TEXT, borderBottom: `1px solid ${p.BORDER}` }}>
                        Consignados ({consignados.length})
                    </div>
                    {consignados.length === 0
                        ? <p className="text-sm px-4 py-8 text-center" style={{ color: p.MUTED }}>
                            {podeMarcar
                                ? 'Nenhum fornecedor consignado ainda. Busque acima e clique no nome.'
                                : 'Nenhum fornecedor consignado ainda.'}
                          </p>
                        : consignados.map(f => (
                            <div key={f.id} className="flex items-center justify-between px-4 py-2.5 group"
                                style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                <div className="flex items-center gap-2.5 min-w-0">
                                    <SeloConsignado p={p} />
                                    <span className="text-sm truncate" style={{ color: p.TEXT }}>{f.nome}</span>
                                </div>
                                {podeMarcar && (
                                    <button onClick={() => alternar(f)}
                                        className="text-xs px-3 py-2 rounded-lg transition acoes-hover"
                                        style={{ color: p.RED }}
                                        onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                        Remover
                                    </button>
                                )}
                            </div>
                        ))}
                </div>
            </div>
        </Secoes>
    );
}

/** O estado na busca: o selo aceso quando já está marcado, apagado quando não. */
function Marca({ ativa, p }: { ativa: boolean; p: Palette }) {
    return ativa
        ? <SeloConsignado p={p} />
        : <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide"
            style={{ color: p.MUTED, border: `1px dashed ${p.MUTED}66` }}>
            CONSIG.
          </span>;
}
