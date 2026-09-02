import { useCallback, useEffect, useState } from 'react';
import { Palette } from '@/lib/tema';
import { dinheiro } from '@/lib/campanha';
import Icone from '@/Components/painel/Icone';

export interface Atendido {
    id: number;
    fornecedor: string;
    faturamento: number | null;
    /** A meta combinada — o percentual sugerido aplicado ao faturamento. */
    investimento: number;
    pago: number;
    /** null quando a meta é zero: ali não existe percentual a mostrar. */
    percentualPago: number | null;
    falta: number;
    em: string;
}

/** O que a tela de cima já sabe do fornecedor escolhido, para incluir sem redigitar. */
export interface Candidato {
    fornecedor: string;
    faturamento: number | null;
    investimento: number | null;
}

const pct = (v: number) => `${v.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;

/**
 * Campo de dinheiro em centavos, igual ao da tela de cima.
 *
 * Guarda dígitos ("1000000") e mostra formatado. É o jeito que não briga com
 * quem digita: cada tecla entra pela direita e não existe estado meio digitado
 * para o programa interpretar errado.
 */
function CampoPago({ valor, onSalvar, p }: {
    valor: number; onSalvar: (v: number) => void; p: Palette;
}) {
    const [digitos, setDigitos] = useState(String(Math.round(valor * 100)));
    const [editando, setEditando] = useState(false);

    useEffect(() => { setDigitos(String(Math.round(valor * 100))); }, [valor]);

    const atual = digitos === '' ? 0 : Number(digitos) / 100;

    const confirmar = () => {
        setEditando(false);
        if (atual !== valor) onSalvar(atual);
    };

    return (
        <input
            inputMode="numeric"
            value={editando || atual > 0 ? dinheiro(atual) : ''}
            placeholder="R$ 0,00"
            onFocus={() => setEditando(true)}
            onBlur={confirmar}
            onKeyDown={e => {
                if (e.key === 'Enter') { (e.target as HTMLInputElement).blur(); return; }
                if (e.key === 'Backspace') { e.preventDefault(); setDigitos(d => d.slice(0, -1)); return; }
                if (/^[0-9]$/.test(e.key)) { e.preventDefault(); setDigitos(d => (d + e.key).replace(/^0+/, '')); }
            }}
            onChange={() => { /* controlado pelo onKeyDown */ }}
            className="w-32 rounded-lg text-sm px-2.5 py-1.5 outline-none text-right"
            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
        />
    );
}

/**
 * A lista de quem já recebeu a campanha, e quanto do combinado já entrou.
 *
 * É de cada comprador: você acompanha os seus, ninguém tromba no trabalho do
 * outro e a lista fica curta o bastante para ser útil no dia a dia.
 */
export default function Atendidos({ candidato, percentualSugerido, p }: {
    /** O fornecedor que está preenchido lá em cima, pronto para incluir. */
    candidato: Candidato;
    percentualSugerido: number;
    p: Palette;
}) {
    const [lista, setLista] = useState<Atendido[]>([]);
    const [carregando, setCarregando] = useState(true);
    const [erro, setErro] = useState<string | null>(null);
    const [incluindo, setIncluindo] = useState(false);

    const carregar = useCallback(async () => {
        try {
            const { data } = await window.axios.get(route('campanha.atendidos'));
            setLista(data.atendidos);
        } catch {
            setErro('Não foi possível carregar a lista.');
        } finally {
            setCarregando(false);
        }
    }, []);

    useEffect(() => { carregar(); }, [carregar]);

    const incluir = async () => {
        setIncluindo(true);
        setErro(null);
        try {
            const { data } = await window.axios.post(route('campanha.atendidos.incluir'), candidato);
            setLista(l => [data.atendido, ...l]);
        } catch (e: any) {
            setErro(e?.response?.data?.erro ?? 'Não foi possível incluir.');
        } finally {
            setIncluindo(false);
        }
    };

    const salvarPago = async (a: Atendido, pago: number) => {
        try {
            const { data } = await window.axios.patch(route('campanha.atendidos.atualizar', a.id), { pago });
            setLista(l => l.map(x => x.id === a.id ? data.atendido : x));
        } catch {
            setErro('Não foi possível salvar o valor pago.');
        }
    };

    const remover = async (a: Atendido) => {
        if (!confirm(`Tirar ${a.fornecedor} da lista?`)) return;
        try {
            await window.axios.delete(route('campanha.atendidos.remover', a.id));
            setLista(l => l.filter(x => x.id !== a.id));
        } catch {
            setErro('Não foi possível remover.');
        }
    };

    const podeIncluir = candidato.fornecedor.trim() !== '' && !incluindo;

    return (
        <section className="rounded-xl p-4 sm:p-5" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            <div className="flex items-start justify-between gap-3 flex-wrap mb-1">
                <div>
                    <h2 className="text-sm font-semibold" style={{ color: p.TEXT }}>Fornecedores atendidos</h2>
                    <p className="text-xs mt-1" style={{ color: p.MUTED }}>
                        Os que você já mandou a campanha, e quanto do combinado já entrou.
                        A meta é {percentualSugerido}% do faturamento e fica congelada no dia do acordo.
                    </p>
                </div>

                <button type="button" onClick={incluir} disabled={!podeIncluir}
                    title={podeIncluir ? '' : 'Escolha o fornecedor lá em cima primeiro'}
                    className="shrink-0 text-sm font-medium px-3 py-1.5 rounded-lg transition disabled:opacity-40"
                    style={{ background: p.ACCENT, color: '#fff' }}>
                    {incluindo ? 'Incluindo...' : '+ Incluir o de cima'}
                </button>
            </div>

            {erro && <p className="text-xs mt-2" style={{ color: p.RED }}>{erro}</p>}

            <div className="mt-3 rounded-lg overflow-hidden" style={{ border: `1px solid ${p.BORDER}` }}>
                {carregando ? (
                    <p className="px-3 py-6 text-center text-sm" style={{ color: p.MUTED }}>Carregando...</p>
                ) : lista.length === 0 ? (
                    <p className="px-3 py-6 text-center text-sm" style={{ color: p.MUTED }}>
                        Ninguém na lista ainda. Preencha o fornecedor acima e clique em incluir.
                    </p>
                ) : (
                    <div className="rolagem-x overflow-x-auto">
                        <table className="min-w-full">
                            <thead>
                                <tr style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                    {['Fornecedor', 'Faturamento', `Meta (${percentualSugerido}%)`, 'Pago', '% pago', 'Falta', ''].map((t, i) => (
                                        <th key={i} className={`px-3 py-2 text-xs font-medium whitespace-nowrap ${i === 0 ? 'text-left' : 'text-right'}`}
                                            style={{ color: p.MUTED }}>{t}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {lista.map(a => {
                                    /* A cor conta a história de longe: quitado, andando, parado.
                                       Verde só em 100% — 99% ainda é uma conversa em aberto. */
                                    const cor = a.percentualPago === null ? p.MUTED
                                        : a.percentualPago >= 100 ? p.GREEN
                                        : a.percentualPago > 0 ? p.AMBER
                                        : p.RED;

                                    return (
                                        <tr key={a.id} style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                            <td className="px-3 py-2 text-sm" style={{ color: p.TEXT }}>{a.fornecedor}</td>
                                            <td className="px-3 py-2 text-sm text-right whitespace-nowrap" style={{ color: p.MUTED }}>
                                                {a.faturamento === null ? '—' : dinheiro(a.faturamento)}
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right whitespace-nowrap" style={{ color: p.TEXT }}>
                                                {dinheiro(a.investimento)}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <CampoPago valor={a.pago} onSalvar={v => salvarPago(a, v)} p={p} />
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right font-semibold whitespace-nowrap" style={{ color: cor }}>
                                                {a.percentualPago === null ? '—' : pct(a.percentualPago)}
                                            </td>
                                            <td className="px-3 py-2 text-sm text-right whitespace-nowrap" style={{ color: a.falta > 0 ? p.TEXT : p.GREEN }}>
                                                {a.falta > 0 ? dinheiro(a.falta) : 'quitado'}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                <button type="button" onClick={() => remover(a)} title="Tirar da lista"
                                                    className="p-1.5 rounded-lg transition" style={{ color: p.MUTED }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M6 18L18 6M6 6l12 12" className="w-4 h-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </section>
    );
}
