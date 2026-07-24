import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { formatDistanceToNowStrict } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { EstadoSino, Notificacao, PageProps } from '@/types';
import { NOTIFICACAO_LABEL, TIPO_CARD_LABEL, notificacaoCor, usePaleta, lojaNome } from '@/lib/tema';
import Icone from './Icone';

const SINO = 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9';

const VAZIO: EstadoSino = { pendentes: 0, itens: [], ativas: true };

/**
 * O sino. O estado inicial vem dos props compartilhados; depois disso quem
 * manda é o canal privado do usuário — inclusive para BAIXAR o contador quando
 * outra pessoa resolve o que gerou o aviso.
 */
export default function SinoNotificacoes({ userId }: { userId: number }) {
    const { isDark, p } = usePaleta();
    const { notificacoes } = usePage<PageProps>().props;

    const [estado, setEstado] = useState<EstadoSino>(notificacoes ?? VAZIO);
    const [aberto, setAberto] = useState(false);
    const caixaRef = useRef<HTMLDivElement>(null);

    // Navegação normal do Inertia também traz o estado novo
    useEffect(() => {
        if (notificacoes) setEstado(notificacoes);
    }, [notificacoes]);

    useEffect(() => {
        window.Echo.private(`usuario.${userId}`)
            .listen('.NotificacoesAtualizadas', (e: EstadoSino) => setEstado(e));

        return () => { window.Echo.leave(`usuario.${userId}`); };
    }, [userId]);

    // Fecha ao clicar fora
    useEffect(() => {
        if (!aberto) return;

        const fora = (ev: MouseEvent) => {
            if (caixaRef.current && !caixaRef.current.contains(ev.target as Node)) {
                setAberto(false);
            }
        };

        document.addEventListener('mousedown', fora);
        return () => document.removeEventListener('mousedown', fora);
    }, [aberto]);

    const abrirNota = (n: Notificacao) => {
        setAberto(false);
        router.post(route('notificacoes.abrir', n.id), {}, { preserveScroll: true });
    };

    const lerTodas = () => {
        router.post(route('notificacoes.lerTodas'), {}, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="relative" ref={caixaRef}>
            <button
                type="button"
                onClick={() => setAberto(a => !a)}
                title={estado.ativas ? 'Notificações' : 'Notificações desligadas no perfil'}
                className={`relative flex items-center justify-center w-9 h-9 rounded-lg transition-all duration-200 ${
                    isDark ? 'bg-[#21262d] text-[#e6edf3] hover:bg-[#30363d]' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                }`}
            >
                <Icone path={SINO} className={`w-5 h-5 ${estado.ativas ? '' : 'opacity-40'}`} />

                {estado.pendentes > 0 && (
                    <span
                        className="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full text-[11px] font-bold flex items-center justify-center text-white"
                        style={{ background: p.RED }}
                    >
                        {estado.pendentes > 9 ? '9+' : estado.pendentes}
                    </span>
                )}
            </button>

            {aberto && (
                <div
                    className="absolute right-0 mt-2 w-[22rem] rounded-lg shadow-xl overflow-hidden z-50"
                    style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}
                >
                    <div
                        className="flex items-center justify-between px-4 py-2.5"
                        style={{ borderBottom: `1px solid ${p.BORDER}` }}
                    >
                        <span className="text-sm font-semibold" style={{ color: p.TEXT }}>
                            Notificações
                        </span>

                        {estado.pendentes > 0 && (
                            <button
                                type="button"
                                onClick={lerTodas}
                                className="text-xs hover:underline"
                                style={{ color: p.ACCENT }}
                            >
                                Marcar todas como lidas
                            </button>
                        )}
                    </div>

                    <div className="max-h-[26rem] overflow-y-auto">
                        {estado.itens.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                {estado.ativas
                                    ? 'Nada por aqui.'
                                    : 'Notificações desligadas no seu perfil.'}
                            </p>
                        ) : (
                            estado.itens.map(n => <Item key={n.id} n={n} onClick={() => abrirNota(n)} />)
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * Uma linha do sino, no formato que o pessoal já usa no grupo:
 *
 *   CHUA
 *   NF 5342 · Loja 01
 *   CUSTO, CADASTRO
 */
function Item({ n, onClick }: { n: Notificacao; onClick: () => void }) {
    const { isDark, p } = usePaleta();
    const cor = notificacaoCor(n.tipo, p);

    const quando = formatDistanceToNowStrict(new Date(n.updated_at), { addSuffix: true, locale: ptBR });

    return (
        <button
            type="button"
            onClick={onClick}
            className={`w-full text-left px-4 py-3 transition ${isDark ? 'hover:bg-[#21262d]' : 'hover:bg-gray-50'}`}
            style={{
                borderBottom: `1px solid ${p.BORDER}`,
                borderLeft: `3px solid ${n.lida ? 'transparent' : cor}`,
                opacity: n.lida ? 0.6 : 1,
            }}
        >
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-semibold truncate" style={{ color: p.TEXT }}>
                    {n.fornecedor ?? '—'}
                </span>
                <span className="text-[11px] shrink-0" style={{ color: p.MUTED }}>
                    {quando}
                </span>
            </div>

            <div className="text-xs mt-0.5" style={{ color: p.MUTED }}>
                NF {n.numero_nota} {n.loja != null && `· ${lojaNome(n.loja)}`}
            </div>

            <div className="text-xs font-medium mt-1" style={{ color: cor }}>
                {n.tipos.length > 0
                    ? n.tipos.map(t => (TIPO_CARD_LABEL[t] ?? t).toUpperCase()).join(', ')
                    : NOTIFICACAO_LABEL[n.tipo]}
            </div>

            {/* Quando há tipos, o rótulo do salto vira a legenda embaixo */}
            {n.tipos.length > 0 && (
                <div className="text-[11px] mt-0.5" style={{ color: p.MUTED }}>
                    {NOTIFICACAO_LABEL[n.tipo]}
                    {n.autor && ` · ${n.autor}`}
                </div>
            )}
        </button>
    );
}
