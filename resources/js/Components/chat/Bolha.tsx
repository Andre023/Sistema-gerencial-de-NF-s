import { useMemo, useRef, useState } from 'react';
import { format, parseISO } from 'date-fns';
import { Mensagem, Reacao } from '@/types';
import { Palette } from '@/lib/tema';
import Icone from '@/Components/painel/Icone';
import Emoji from '@/Components/painel/Emoji';
import AnexoDaMensagem from './AnexoDaMensagem';
import TextoComEmoji from './TextoComEmoji';
import BarraReacoes from './BarraReacoes';

const hora = (iso: string) => {
    try { return format(parseISO(iso), 'HH:mm'); } catch { return ''; }
};

/** Quanto tempo o dedo fica parado até a barra de reações abrir (celular). */
const PRESSAO_LONGA = 450;

/**
 * Uma mensagem. Minhas à direita e em azul, as do outro à esquerda — a mesma
 * convenção do WhatsApp, que todo mundo aqui já lê sem precisar aprender.
 *
 * O ✓ só existe do meu lado: é informação sobre o que EU mandei.
 *   ✓  entregue    ✓✓ lido    ⏱ ainda subindo    ⚠ falhou
 *
 * ── Reagir ────────────────────────────────────────────────────────────────
 * Duas portas para a mesma barra, porque metade do galpão usa celular:
 * no computador aparece um rostinho ao passar o mouse; no celular, segurar o
 * dedo na bolha abre a barra.
 */
export default function Bolha({ mensagem, minha, lido, meuId, onReagir, p }: {
    mensagem: Mensagem;
    minha: boolean;
    lido: boolean;
    meuId: number;
    onReagir: (emoji: string) => void;
    p: Palette;
}) {
    const [barraAberta, setBarraAberta] = useState(false);

    const temTexto = !!mensagem.texto;
    const fundo    = minha ? p.ACCENT : p.HOVER_ROW;
    const cor      = minha ? '#ffffff' : p.TEXT;

    /*
     * As reações agrupadas por emoji.
     *
     * O servidor manda a lista crua (um par {emoji, quem} por reação) porque o
     * mesmo payload vai para os dois lados da conversa — lá não dá para saber
     * qual delas é "minha". Aqui dá.
     */
    const grupos = useMemo(
        () => agrupar(mensagem.reacoes ?? [], meuId),
        [mensagem.reacoes, meuId],
    );

    /** O emoji que EU pus (a barra acende ele, e clicar nele de novo tira). */
    const minhaReacao = useMemo(
        () => (mensagem.reacoes ?? []).find(r => r.user_id === meuId)?.emoji ?? null,
        [mensagem.reacoes, meuId],
    );

    /*
     * A pressão longa do celular.
     *
     * O timer é cancelado no dedo levantado E no dedo arrastado: sem o segundo,
     * rolar a conversa com o dedo começando em cima de uma bolha abriria a
     * barra no meio da rolagem.
     */
    const timer = useRef<number | null>(null);

    const soltarTimer = () => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
    };

    // Mensagem que ainda não voltou do servidor não tem id real para reagir
    const podeReagir = mensagem.id > 0 && !mensagem.pendente && !mensagem.falhou;

    const segurar = () => {
        if (!podeReagir) return;

        soltarTimer();
        timer.current = window.setTimeout(() => setBarraAberta(true), PRESSAO_LONGA);
    };

    const escolher = (emoji: string) => {
        setBarraAberta(false);
        onReagir(emoji);
    };

    return (
        <div className={`flex group ${minha ? 'justify-end' : 'justify-start'}`}>

            {/* O rostinho do computador. `order` põe ele SEMPRE do lado de fora
                da bolha: à esquerda das minhas, à direita das do outro — nunca
                por cima do texto. Só aparece no hover, e some no celular
                (onde quem manda é a pressão longa). */}
            {podeReagir && (
                <button
                    type="button"
                    title="Reagir"
                    onClick={() => setBarraAberta(a => !a)}
                    className={`hidden md:flex self-center shrink-0 p-1 mx-1 rounded-full
                                opacity-0 group-hover:opacity-60 hover:!opacity-100 transition
                                ${minha ? 'order-first' : 'order-last'}`}
                    style={{ color: p.MUTED }}
                >
                    <Icone path="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                        className="w-4 h-4" />
                </button>
            )}

            {/* `relative` é o berço da barra de reações, que se posiciona por
                cima desta bolha (bottom-full) sem empurrar a conversa. */}
            <div className="relative max-w-[85%]">

                {barraAberta && (
                    <BarraReacoes
                        minha={minha}
                        atual={minhaReacao}
                        onEscolher={escolher}
                        onFechar={() => setBarraAberta(false)}
                        p={p}
                    />
                )}

                <div
                    onTouchStart={segurar}
                    onTouchEnd={soltarTimer}
                    onTouchMove={soltarTimer}
                    onTouchCancel={soltarTimer}
                    className="rounded-2xl px-3 py-2 space-y-1.5"
                    style={{
                        background: fundo,
                        color: cor,
                        // O "rabinho" achatado do lado de quem falou
                        borderBottomRightRadius: minha ? 4 : undefined,
                        borderBottomLeftRadius: minha ? undefined : 4,
                        opacity: mensagem.pendente ? 0.65 : 1,
                        // Sem isto, segurar o dedo no iPhone abre o menu de
                        // "copiar/compartilhar" do sistema por cima da barra.
                        WebkitTouchCallout: 'none',
                    }}>

                    {mensagem.anexo && (
                        <AnexoDaMensagem
                            mensagemId={mensagem.id}
                            anexo={mensagem.anexo}
                            minha={minha}
                            p={p}
                        />
                    )}

                    {temTexto && (
                        <p className="text-sm whitespace-pre-wrap break-words leading-snug">
                            {/* Emoji vai como imagem (Noto), não pela fonte do
                                sistema: senão o mesmo símbolo muda de cara entre
                                Windows 10 e 11 — e os mais novos viram quadradinho
                                nas máquinas antigas. */}
                            <TextoComEmoji texto={mensagem.texto!} />
                        </p>
                    )}

                    <div className="flex items-center justify-end gap-1 -mb-0.5">
                        <span className="text-[10px]" style={{ opacity: 0.7 }}>
                            {hora(mensagem.created_at)}
                        </span>

                        {minha && <Marca mensagem={mensagem} lido={lido} />}
                    </div>
                </div>

                {/* ── As reações, penduradas embaixo da bolha ──
                    Clicar numa que já existe põe ou tira a MINHA — é o caminho
                    curto para concordar com o que já está lá, sem abrir a barra. */}
                {grupos.length > 0 && (
                    <div className={`flex flex-wrap gap-1 mt-1 ${minha ? 'justify-end' : 'justify-start'}`}>
                        {grupos.map(g => (
                            <button
                                key={g.emoji}
                                type="button"
                                onClick={() => onReagir(g.emoji)}
                                title={g.minha ? 'Tirar a sua reação' : `Reagir com ${g.emoji}`}
                                className="flex items-center gap-0.5 pl-1 pr-1.5 py-0.5 rounded-full transition hover:opacity-80"
                                style={{
                                    background: p.SURFACE,
                                    // A minha fica com a borda acesa — é como se
                                    // sabe, num emoji com 3, se você é um dos três.
                                    border: `1px solid ${g.minha ? p.ACCENT : p.BORDER}`,
                                }}
                            >
                                <Emoji emoji={g.emoji} size={13} />
                                {g.quantidade > 1 && (
                                    <span className="text-[10px] font-medium"
                                        style={{ color: g.minha ? p.ACCENT : p.MUTED }}>
                                        {g.quantidade}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

interface Grupo { emoji: string; quantidade: number; minha: boolean }

/**
 * Junta as reações por emoji, na ordem em que o primeiro de cada um chegou.
 *
 * O Map preserva a ordem de inserção, então o emoji que apareceu primeiro fica
 * na frente e a fileira não embaralha a cada pessoa que reage.
 */
function agrupar(reacoes: Reacao[], meuId: number): Grupo[] {
    const mapa = new Map<string, Grupo>();

    for (const r of reacoes) {
        const grupo = mapa.get(r.emoji) ?? { emoji: r.emoji, quantidade: 0, minha: false };

        grupo.quantidade++;
        if (r.user_id === meuId) grupo.minha = true;

        mapa.set(r.emoji, grupo);
    }

    return [...mapa.values()];
}

/** O indicador de estado das minhas mensagens. */
function Marca({ mensagem, lido }: { mensagem: Mensagem; lido: boolean }) {
    if (mensagem.falhou) {
        return (
            <span title="Não foi enviada">
                <Icone path="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" className="w-3 h-3" />
            </span>
        );
    }

    if (mensagem.pendente) {
        return (
            <span title="Enviando">
                <Icone path="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" className="w-3 h-3" />
            </span>
        );
    }

    // Dois riscos sobrepostos formam o ✓✓ sem precisar de ícone novo
    return (
        <span title={lido ? 'Lida' : 'Entregue'} className="relative inline-flex items-center"
            style={{ opacity: lido ? 1 : 0.65, width: lido ? 16 : 11 }}>
            <Icone path="M5 13l4 4L19 7" className="w-3 h-3 absolute left-0" />
            {lido && <Icone path="M5 13l4 4L19 7" className="w-3 h-3 absolute left-[5px]" />}
        </span>
    );
}
