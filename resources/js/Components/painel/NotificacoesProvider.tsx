import { createContext, PropsWithChildren, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { EstadoSino, Notificacao, PageProps } from '@/types';
import { NOTIFICACAO_LABEL, TIPO_CARD_LABEL, lojaNome } from '@/lib/tema';
import AvisosNaTela, { AvisoNaTela } from './AvisosNaTela';

const VAZIO: EstadoSino = { pendentes: 0, itens: [], ativas: true };

/** Quantos cards ficam empilhados na tela ao mesmo tempo */
const MAX_NA_TELA = 4;

interface ContextoNotificacoes {
    estado: EstadoSino;
    suportado: boolean;
    permissao: NotificationPermission;
    pedirPermissao: () => void;
    abrirNota: (n: Notificacao) => void;
    lerTodas: () => void;
}

const Contexto = createContext<ContextoNotificacoes | null>(null);

export function useNotificacoes(): ContextoNotificacoes {
    const ctx = useContext(Contexto);
    if (!ctx) throw new Error('useNotificacoes precisa estar dentro de <NotificacoesProvider>');
    return ctx;
}

/** Rótulo curto dos tipos ("CUSTO, CADASTRO") ou o motivo do salto */
export function resumoTipos(n: Notificacao): string {
    return n.tipos.length
        ? n.tipos.map(t => (TIPO_CARD_LABEL[t] ?? t).toUpperCase()).join(', ')
        : NOTIFICACAO_LABEL[n.tipo];
}

/**
 * Cérebro das notificações — montado UMA vez pelo layout.
 *
 * Antes isso morava dentro do sino, que o layout renderiza duas vezes (desktop
 * e mobile ficam os dois no DOM, escondidos por CSS). Resultado: o canal era
 * assinado em dobro e sair de um derrubava o outro. Aqui há um só.
 *
 * Como o aviso chega depende de onde a pessoa está olhando — igual ao WhatsApp:
 *   • aba na frente  → card no canto inferior direito, dentro do sistema
 *   • aba escondida  → notificação do sistema operacional (exige HTTPS)
 */
export default function NotificacoesProvider({ userId, children }: PropsWithChildren<{ userId: number }>) {
    const { notificacoes } = usePage<PageProps>().props;

    const [estado, setEstado] = useState<EstadoSino>(notificacoes ?? VAZIO);
    const [avisos, setAvisos] = useState<AvisoNaTela[]>([]);

    // Navegação normal do Inertia também traz o estado novo
    useEffect(() => {
        if (notificacoes) setEstado(notificacoes);
    }, [notificacoes]);

    useEffect(() => {
        window.Echo.private(`usuario.${userId}`)
            .listen('.NotificacoesAtualizadas', (e: EstadoSino) => setEstado(e));

        return () => { window.Echo.leave(`usuario.${userId}`); };
    }, [userId]);

    // ── Permissão para o aviso do sistema operacional ─────────────────────────
    const suportado = typeof window !== 'undefined' && 'Notification' in window;
    const [permissao, setPermissao] = useState<NotificationPermission>(
        suportado ? Notification.permission : 'denied'
    );

    const pedirPermissao = useCallback(async () => {
        if (!suportado) return;
        setPermissao(await Notification.requestPermission());
    }, [suportado]);

    // Pede a permissão logo ao entrar no sistema. Só tenta uma vez por navegador:
    // se a pessoa dispensar, o botão dentro do sino continua disponível.
    useEffect(() => {
        if (!suportado || permissao !== 'default') return;
        if (localStorage.getItem('nfs_permissao_pedida')) return;

        localStorage.setItem('nfs_permissao_pedida', '1');
        Notification.requestPermission().then(setPermissao).catch(() => {});
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ── Bipe curto, no espírito do toque do WhatsApp ──────────────────────────
    // Gerado na hora (sem arquivo de áudio). O navegador só libera som depois de
    // algum clique na página; antes disso a chamada falha calada, de propósito.
    const audioRef = useRef<AudioContext | null>(null);

    const bipar = useCallback(() => {
        try {
            const Ctor = window.AudioContext ?? (window as any).webkitAudioContext;
            if (!Ctor) return;

            const ctx = audioRef.current ?? (audioRef.current = new Ctor());
            if (ctx.state === 'suspended') ctx.resume().catch(() => {});

            // Duas notas subindo, bem curtas
            [880, 1175].forEach((hz, i) => {
                const osc = ctx.createOscillator();
                const vol = ctx.createGain();
                const t0 = ctx.currentTime + i * 0.12;

                osc.type = 'sine';
                osc.frequency.value = hz;
                vol.gain.setValueAtTime(0.0001, t0);
                vol.gain.exponentialRampToValueAtTime(0.12, t0 + 0.02);
                vol.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.16);

                osc.connect(vol).connect(ctx.destination);
                osc.start(t0);
                osc.stop(t0 + 0.18);
            });
        } catch {
            // som é enfeite; nunca deve atrapalhar o aviso
        }
    }, []);

    // ── Ações ─────────────────────────────────────────────────────────────────
    const fecharAviso = useCallback((chave: string) => {
        setAvisos(a => a.filter(x => x.chave !== chave));
    }, []);

    const abrirNota = useCallback((n: Notificacao) => {
        router.post(route('notificacoes.abrir', n.id), {}, { preserveScroll: true });
    }, []);

    const lerTodas = useCallback(() => {
        router.post(route('notificacoes.lerTodas'), {}, { preserveScroll: true, preserveState: true });
    }, []);

    // ── Detecção do que é novo ────────────────────────────────────────────────
    // id → updated_at do que já foi avisado. Comparamos o updated_at (e não só o
    // id) porque existe UMA notificação viva por nota: quando chega outra
    // divergência, a mesma linha é atualizada — e isso também precisa avisar.
    // null = ainda não sabemos o que já existia (não avisar o histórico no load)
    const vistos = useRef<Map<number, string> | null>(null);

    useEffect(() => {
        const atual = new Map(estado.itens.map(i => [i.id, i.updated_at] as const));

        // Primeira passada: só memoriza o que já estava lá
        if (vistos.current === null) { vistos.current = atual; return; }

        // Nova OU atualizada (mesma nota que recebeu mais uma divergência)
        const novas = estado.itens.filter(
            i => !i.lida && vistos.current!.get(i.id) !== i.updated_at
        );
        vistos.current = atual;

        if (!novas.length || !estado.ativas) return;

        // Com a aba na frente, o card dentro do sistema já resolve — e ainda
        // aparece para quem nunca autorizou o aviso do sistema operacional.
        if (!document.hidden) {
            setAvisos(a => [
                ...novas.map(n => ({ chave: `${n.id}-${n.updated_at}`, notificacao: n })),
                ...a,
            ].slice(0, MAX_NA_TELA));

            bipar();
            return;
        }

        // Aba escondida (outra aba, outra janela, minimizado): só o sistema
        // operacional alcança a pessoa.
        if (permissao !== 'granted') return;

        novas.forEach(n => {
            const aviso = new Notification(n.fornecedor ?? 'Nota fiscal', {
                body: `NF ${n.numero_nota}${n.loja != null ? ` · ${lojaNome(n.loja)}` : ''}\n${resumoTipos(n)}`,
                // O updated_at entra na tag de propósito: cada atualização vira um
                // card NOVO (sempre alerta). Só uma reentrega idêntica do mesmo
                // evento é descartada — que é o que a tag deve evitar.
                tag: `nota-${n.id}-${n.updated_at}`,
                icon: '/favicon.ico',
                // Mantém a faixa na tela até a pessoa interagir, em vez de sumir
                // sozinha em ~5s (que faz o aviso passar despercebido)
                requireInteraction: true,
            });

            aviso.onclick = () => {
                window.focus();
                aviso.close();
                abrirNota(n);
            };
        });
    }, [estado, permissao, bipar, abrirNota]);

    return (
        <Contexto.Provider value={{ estado, suportado, permissao, pedirPermissao, abrirNota, lerTodas }}>
            {children}

            <AvisosNaTela
                avisos={avisos}
                onFechar={fecharAviso}
                onAbrir={n => { abrirNota(n); }}
            />
        </Contexto.Provider>
    );
}
