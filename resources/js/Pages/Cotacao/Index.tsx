import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT } from '@/lib/tema';

interface Resumo {
    id: number;
    titulo: string;
    criada_por: string | null;
    criada_em: string;
    alterada_em: string;
    listas: number;
    respostas: number;
    compras: number;
}

/**
 * A lista de cotações. Cada rodada da CEASA é uma — a da semana passada fica
 * para consultar o preço que cada fornecedor deu.
 */
export default function Index({ cotacoes }: { cotacoes: Resumo[] }) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const [criando, setCriando] = useState(false);

    const nova = () => {
        setCriando(true);
        router.post(route('cotacao.store'), {}, { onFinish: () => setCriando(false) });
    };

    const data = (iso: string) => new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    const etapa = (c: Resumo) =>
        c.compras ? { texto: 'Planilha de compra', cor: p.GREEN }
            : c.respostas ? { texto: `${c.respostas} resposta(s)`, cor: p.AMBER }
            : c.listas ? { texto: `${c.listas} lista(s) de compra`, cor: p.ACCENT }
            : { texto: 'Vazia', cor: p.MUTED };

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Cotação" />

            <div className="flex-1 w-full py-6 px-4 sm:px-6 lg:px-8 max-w-screen-lg mx-auto" style={{ background: p.BG }}>
                <div className="flex flex-wrap items-end gap-3 mb-5">
                    <div>
                        <h1 className="text-lg font-semibold" style={{ color: p.TEXT }}>Cotação CEASA</h1>
                        <p className="text-sm mt-1" style={{ color: p.MUTED }}>
                            Mercearia, Bebidas e Frios das duas lojas: as listas de compra, a cotação dos fornecedores e a planilha do pedido.
                        </p>
                    </div>
                    <button
                        onClick={nova}
                        disabled={criando}
                        className="ml-auto text-sm font-medium px-4 py-2 rounded-lg transition disabled:opacity-40"
                        style={{ background: p.ACCENT, color: '#fff' }}
                    >
                        {criando ? 'Criando…' : '+ Nova cotação'}
                    </button>
                </div>

                {cotacoes.length === 0 ? (
                    <div className="rounded-xl p-10 text-center text-sm" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}`, color: p.MUTED }}>
                        Nenhuma cotação ainda. Comece uma nova e coloque as listas de estoque baixo das lojas.
                    </div>
                ) : (
                    <ul className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                        {cotacoes.map((c, i) => {
                            const e = etapa(c);
                            return (
                                <li key={c.id} style={{ borderTop: i ? `1px solid ${p.BORDER}` : undefined }}>
                                    <Link href={route('cotacao.mostrar', c.id)}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 transition"
                                        onMouseEnter={ev => (ev.currentTarget.style.background = p.HOVER_ROW)}
                                        onMouseLeave={ev => (ev.currentTarget.style.background = 'transparent')}>
                                        <span className="font-medium text-sm" style={{ color: p.TEXT }}>{c.titulo}</span>
                                        <span className="text-xs px-2 py-0.5 rounded-full" style={{ color: e.cor, border: `1px solid ${e.cor}55` }}>{e.texto}</span>
                                        <span className="ml-auto text-xs" style={{ color: p.MUTED }}>
                                            {c.criada_por ? `${c.criada_por} · ` : ''}alterada em {data(c.alterada_em)}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
