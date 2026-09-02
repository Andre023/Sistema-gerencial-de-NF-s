import { useCallback, useEffect, useState } from 'react';
import { Palette } from '@/lib/tema';
import { dinheiro } from '@/lib/campanha';
import Icone from '@/Components/painel/Icone';

export interface Atendido {
    id: number;
    /** De quem e a linha — a lista e de todos e filtra por comprador. */
    user_id: number;
    comprador: string;
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
 * A forma comparável de um nome, para o AVISO da tela.
 *
 * Espelha o CampanhaFornecedor::chaveDe do servidor, mas de propósito só serve
 * de dica: quem decide de verdade é o servidor, que recusa a inclusão com a
 * mensagem certa. Se as duas regras divergirem um dia, o pior que acontece é o
 * aviso não aparecer antes — nunca uma linha duplicada entrar.
 */
const chaveDe = (nome: string) =>
    nome.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^A-Za-z0-9 ]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toUpperCase();

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
 * Todos veem tudo, e o filtro por comprador fica em cima. Foi de propósito: é
 * assim que dois compradores param de bater no mesmo fornecedor sem saber — e é
 * a mesma informação que alimenta o aviso ao escolher um que já está com
 * alguém. MEXER em cada linha continua sendo do dono dela (ou do admin).
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
    /** null = todos. Filtra por comprador sem ir ao servidor: a lista ja veio. */
    const [filtro, setFiltro] = useState<number | null>(null);

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

    /*
     * Os compradores que aparecem na lista, em ordem, sem repetir.
     *
     * Sai da propria lista e nao de uma consulta de usuarios: quem nunca
     * incluiu ninguem nao vira um botao vazio para clicar.
     */
    const compradores = Array.from(
        new Map(lista.map(a => [a.user_id, a.comprador])).entries(),
    ).sort((a, b) => a[1].localeCompare(b[1]));

    const listaFiltrada = filtro === null ? lista : lista.filter(a => a.user_id === filtro);

    /**
     * O fornecedor escolhido la em cima ja esta com alguem?
     *
     * O aviso aparece ANTES de tentar incluir: descobrir no erro, depois de
     * preencher faturamento e investimento, e descobrir tarde.
     */
    const jaEstaCom = candidato.fornecedor.trim() === ''
        ? null
        : lista.find(a => chaveDe(a.fornecedor) === chaveDe(candidato.fornecedor)) ?? null;

    const podeIncluir = candidato.fornecedor.trim() !== '' && !incluindo;

    return (
        <section className="rounded-xl p-4 sm:p-5" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            <div className="flex items-start justify-between gap-3 flex-wrap mb-1">
                <div>
                    <h2 className="text-sm font-semibold" style={{ color: p.TEXT }}>Fornecedores atendidos</h2>
                    <p className="text-xs mt-1" style={{ color: p.MUTED }}>
                        Quem já recebeu a campanha, e quanto do combinado já entrou.
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

            {jaEstaCom && (
                <p className="text-xs rounded-lg px-3 py-2 mt-3"
                    style={{ background: p.AMBER + '14', color: p.AMBER, border: `1px solid ${p.AMBER}33` }}>
                    <strong>{jaEstaCom.comprador}</strong> já incluiu <strong>{jaEstaCom.fornecedor}</strong> na
                    lista. Vale falar com {jaEstaCom.comprador.split(' ')[0]} antes de mandar a carta.
                </p>
            )}

            {erro && <p className="text-xs mt-2" style={{ color: p.RED }}>{erro}</p>}

            {/* Filtro por comprador. Fica em cima da lista porque a pergunta
                ("o que o Clayton fez?") vem antes de olhar as linhas. */}
            {compradores.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mt-3">
                    {([[null, `Todos (${lista.length})`] as [number | null, string]])
                        .concat(compradores.map(([id, nome]) =>
                            [id, `${nome.split(' ')[0]} (${lista.filter(a => a.user_id === id).length})`]))
                        .map(([id, rotulo]) => {
                            const ativo = filtro === id;

                            return (
                                <button key={String(id)} type="button" onClick={() => setFiltro(id)}
                                    className="text-xs px-2.5 py-1 rounded-lg transition"
                                    style={{
                                        background: ativo ? p.ACCENT + '1a' : 'transparent',
                                        color: ativo ? p.ACCENT : p.MUTED,
                                        border: `1px solid ${ativo ? p.ACCENT + '55' : p.BORDER}`,
                                    }}>
                                    {rotulo}
                                </button>
                            );
                        })}
                </div>
            )}

            <div className="mt-3 rounded-lg overflow-hidden" style={{ border: `1px solid ${p.BORDER}` }}>
                {carregando ? (
                    <p className="px-3 py-6 text-center text-sm" style={{ color: p.MUTED }}>Carregando...</p>
                ) : listaFiltrada.length === 0 ? (
                    <p className="px-3 py-6 text-center text-sm" style={{ color: p.MUTED }}>
                        {lista.length === 0
                            ? 'Ninguém na lista ainda. Preencha o fornecedor acima e clique em incluir.'
                            : 'Este comprador ainda não incluiu ninguém.'}
                    </p>
                ) : (
                    <div className="rolagem-x overflow-x-auto">
                        <table className="min-w-full">
                            <thead>
                                <tr style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                    {['Comprador', 'Fornecedor', 'Faturamento', `Meta (${percentualSugerido}%)`, 'Pago', '% pago', 'Falta', ''].map((t, i) => (
                                        <th key={i} className={`px-3 py-2 text-xs font-medium whitespace-nowrap ${i === 0 ? 'text-left' : 'text-right'}`}
                                            style={{ color: p.MUTED }}>{t}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {listaFiltrada.map(a => {
                                    /* A cor conta a história de longe: quitado, andando, parado.
                                       Verde só em 100% — 99% ainda é uma conversa em aberto. */
                                    const cor = a.percentualPago === null ? p.MUTED
                                        : a.percentualPago >= 100 ? p.GREEN
                                        : a.percentualPago > 0 ? p.AMBER
                                        : p.RED;

                                    return (
                                        <tr key={a.id} style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                            <td className="px-3 py-2 text-sm whitespace-nowrap" style={{ color: p.MUTED }}>
                                                {a.comprador.split(' ')[0]}
                                            </td>
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

            {/* Exportar leva a lista INTEIRA, e nao a fatia filtrada: quem baixa
                quer o panorama, e filtrar por nome no proprio Excel e um clique.
                Mandar so o que esta na tela daria um arquivo que engana quem o
                recebe por e-mail. */}
            {lista.length > 0 && (
                <div className="flex justify-end mt-3">
                    <a href={route('campanha.atendidos.exportar')}
                        className="inline-flex items-center gap-1.5 text-sm px-3 py-2 rounded-lg transition"
                        style={{ color: p.GREEN, border: `1px solid ${p.GREEN}55` }}>
                        <Icone path="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"
                            className="w-4 h-4" />
                        Exportar para Excel ({lista.length})
                    </a>
                </div>
            )}
        </section>
    );
}
