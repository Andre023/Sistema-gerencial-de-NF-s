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

    // ── Aviso nativo do sistema (card no canto + central de notificações) ──────
    // Exige HTTPS; o navegador bloqueia a API em HTTP puro.
    const suportado = typeof window !== 'undefined' && 'Notification' in window;
    const [permissao, setPermissao] = useState<NotificationPermission>(
        suportado ? Notification.permission : 'denied'
    );
    // id → updated_at do que já foi avisado. Comparamos o updated_at (e não só o
    // id) porque existe UMA notificação viva por nota: quando chega outra
    // divergência, a mesma linha é atualizada — e isso também precisa avisar.
    // null = ainda não sabemos o que já existia (não avisar o histórico no load)
    const vistos = useRef<Map<number, string> | null>(null);

    const pedirPermissao = async () => {
        if (!suportado) return;
        setPermissao(await Notification.requestPermission());
    };

    // Pede a permissão logo ao entrar no sistema. Só tenta uma vez por navegador:
    // se a pessoa dispensar, o botão dentro do sino continua disponível.
    useEffect(() => {
        if (!suportado || permissao !== 'default') return;
        if (localStorage.getItem('nfs_permissao_pedida')) return;

        localStorage.setItem('nfs_permissao_pedida', '1');
        Notification.requestPermission().then(setPermissao).catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const atual = new Map(estado.itens.map(i => [i.id, i.updated_at] as const));

        // Primeira passada: só memoriza o que já estava lá
        if (vistos.current === null) { vistos.current = atual; return; }

        // Nova OU atualizada (mesma nota que recebeu mais uma divergência)
        const novas = estado.itens.filter(
            i => !i.lida && vistos.current!.get(i.id) !== i.updated_at
        );
        vistos.current = atual;

        if (!novas.length || !estado.ativas || permissao !== 'granted') return;
        // Se a pessoa está olhando a tela, o sino já basta — não enche de card
        if (!document.hidden && document.hasFocus()) return;

        novas.forEach(n => {
            const tipos = n.tipos.length
                ? n.tipos.map(t => (TIPO_CARD_LABEL[t] ?? t).toUpperCase()).join(', ')
                : NOTIFICACAO_LABEL[n.tipo];

            const aviso = new Notification(n.fornecedor ?? 'Nota fiscal', {
                body: `NF ${n.numero_nota}${n.loja != null ? ` · ${lojaNome(n.loja)}` : ''}\n${tipos}`,
                // O updated_at entra na tag de propósito: cada atualização vira um
                // card NOVO (sempre alerta). Só uma reentrega idêntica do mesmo
                // evento é descartada — que é o que a tag deve evitar.
                tag: `nota-${n.id}-${n.updated_at}`,
                icon: '/favicon.ico',
            });

            aviso.onclick = () => {
                window.focus();
                aviso.close();
                router.post(route('notificacoes.abrir', n.id), {}, { preserveScroll: true });
            };
        });
    }, [estado, permissao]);

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

                    {/* Avisos na área de trabalho: o navegador exige autorização do usuário */}
                    {suportado && permissao === 'default' && (
                        <button
                            type="button"
                            onClick={pedirPermissao}
                            className="w-full text-left px-4 py-2.5 text-xs leading-snug transition hover:underline"
                            style={{ background: p.ACCENT + '14', color: p.ACCENT, borderBottom: `1px solid ${p.BORDER}` }}
                        >
                            Ativar avisos na área de trabalho
                            <span className="block text-[11px] opacity-80 no-underline">
                                Aparecem no canto da tela mesmo com o sistema em outra janela
                            </span>
                        </button>
                    )}

                    {suportado && permissao === 'denied' && (
                        <p
                            className="px-4 py-2.5 text-[11px] leading-snug"
                            style={{ color: p.MUTED, borderBottom: `1px solid ${p.BORDER}` }}
                        >
                            Avisos na área de trabalho bloqueados. Para liberar, clique no cadeado
                            na barra de endereços do navegador e permita as notificações.
                        </p>
                    )}

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
