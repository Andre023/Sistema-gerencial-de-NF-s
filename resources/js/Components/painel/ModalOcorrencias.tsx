import { useState, useEffect, useCallback } from 'react';
import { format, parseISO } from 'date-fns';
import { Palette } from '@/lib/tema';
import Modal from './Modal';

/** Uma linha do livro: o verbo, quem fez, quando, e o corpo próprio do verbo. */
export interface Ocorrencia {
    id: number;
    acao: string;
    dados: Record<string, any> | null;
    usuario: string;
    em: string;
}

const quando = (iso: string) => {
    try { return format(parseISO(iso), "dd/MM 'às' HH:mm"); } catch { return iso; }
};

/**
 * O que cada verbo diz na tela, já no passado e com o sujeito na frente
 * ("Ana lançou a nota"). O verbo cru (nota_lancada) nunca aparece.
 */
const FRASE: Record<string, string> = {
    nota_lancada:      'lançou a nota',
    nota_editada:      'editou a nota',
    nota_liberada:     'liberou a nota',
    nota_devolvida:    'devolveu ao recebimento',
    nota_cancelada:    'cancelou a nota',
    nota_descancelada: 'desfez o cancelamento',
    nota_movida:       'mudou a nota de fila',
    nota_recebida:     'marcou como recebida hoje',
    nota_excluida:     'excluiu a nota',
    card_aberto:       'abriu divergência',
    card_corrigido:    'corrigiu',
    card_resolvido:    'resolveu',
    card_reaberto:     'reabriu',
    card_excluido:     'excluiu a divergência',
    comentario_criado:   'comentou',
    comentario_excluido: 'apagou um comentário',
    anexo_enviado:  'anexou',
    anexo_removido: 'removeu o anexo',
};

const FILA: Record<string, string> = {
    recebimento: 'Caminhão na porta',
    pre_lote: 'Pré-lote',
};

/**
 * A cor diz o peso da ocorrência antes de a pessoa ler a linha.
 *
 * Vermelho é só para o que APAGA — excluir nota, card, comentário ou anexo. São
 * as ações que antes não deixavam rastro nenhum, e são as que alguém vai
 * procurar aqui. Verde fecha (liberou, corrigiu, resolveu), âmbar desfaz
 * (devolveu, descancelou, reabriu), e o resto é cinza.
 */
function cor(acao: string, p: Palette): string {
    if (acao.endsWith('_excluido') || acao === 'nota_excluida' || acao === 'anexo_removido') return p.RED;
    if (['nota_liberada', 'card_corrigido', 'card_resolvido'].includes(acao)) return p.GREEN;
    if (['nota_devolvida', 'nota_descancelada', 'card_reaberto', 'nota_cancelada'].includes(acao)) return p.AMBER;
    return p.MUTED;
}

/** O rótulo curto que segue a frase: o tipo do card, o nome do arquivo. */
function complemento(o: Ocorrencia): string | null {
    const d = o.dados ?? {};

    if (o.acao.startsWith('card_')) return typeof d.tipo === 'string' ? d.tipo.replace(/_/g, ' ') : null;
    if (o.acao.startsWith('anexo_')) return typeof d.nome === 'string' ? d.nome : null;

    return null;
}

export default function ModalOcorrencias({ aberto, onFechar, baseUrl, titulo, recarregarToken, p }: {
    aberto: boolean;
    onFechar: () => void;
    /** Ex.: "/notas/12/ocorrencias" */
    baseUrl: string | null;
    titulo: string;
    /** Muda quando chega evento do Echo — refaz a busca com a janela aberta. */
    recarregarToken?: number;
    p: Palette;
}) {
    const [itens, setItens] = useState<Ocorrencia[]>([]);
    const [campos, setCampos] = useState<Record<string, string>>({});
    const [carregando, setCarregando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);

    const buscar = useCallback(async () => {
        if (!baseUrl) return;
        setCarregando(true);
        setErro(null);
        try {
            const { data } = await window.axios.get(baseUrl);
            setItens(data.ocorrencias);
            // Os rótulos de campo vêm do servidor junto da lista: uma cópia aqui
            // sairia de sincronia no dia em que a nota ganhasse um campo novo.
            setCampos(data.campos ?? {});
        } catch {
            setErro('Não foi possível carregar as ocorrências.');
        } finally {
            setCarregando(false);
        }
    }, [baseUrl]);

    useEffect(() => { if (aberto) buscar(); }, [aberto, buscar]);

    useEffect(() => {
        if (aberto && recarregarToken !== undefined) buscar();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [recarregarToken]);

    /** "trocou a loja de 1 para 7" — uma linha por campo mexido. */
    const edicoes = (o: Ocorrencia) => {
        const mudou = o.dados?.campos as Record<string, { de: unknown; para: unknown }> | undefined;
        if (!mudou) return null;

        const vazio = (v: unknown) => v === null || v === undefined || v === '';

        return Object.entries(mudou).map(([campo, { de, para }]) => (
            <div key={campo} className="text-xs mt-0.5" style={{ color: p.MUTED }}>
                {campos[campo] ?? campo}:{' '}
                {vazio(de)
                    ? <em>vazio</em>
                    : <span style={{ textDecoration: 'line-through' }}>{String(de)}</span>}
                {' → '}
                {vazio(para) ? <em>vazio</em> : <strong style={{ color: p.TEXT }}>{String(para)}</strong>}
            </div>
        ));
    };

    /** O corpo que só alguns verbos têm: o texto apagado, o motivo, a fila. */
    const detalhe = (o: Ocorrencia) => {
        const d = o.dados ?? {};
        const ctx = (d.contexto ?? {}) as Record<string, any>;

        if (o.acao === 'comentario_criado' || o.acao === 'comentario_excluido') {
            return (
                <p className="text-xs mt-1 rounded px-2 py-1.5 whitespace-pre-wrap break-words"
                    style={{ background: p.HOVER_ROW, color: p.MUTED }}>
                    “{d.texto}”
                    {/* Quem escreveu só aparece quando NÃO é quem apagou — é a
                        diferença entre alguém se retratar e alguém calar o outro. */}
                    {o.acao === 'comentario_excluido' && d.autor && d.autor !== o.usuario && (
                        <span className="block mt-0.5 opacity-70">escrito por {d.autor}</span>
                    )}
                </p>
            );
        }

        if (o.acao === 'card_excluido' && d.detalhe) {
            return <p className="text-xs mt-0.5" style={{ color: p.MUTED }}>“{d.detalhe}”</p>;
        }

        if (o.acao === 'nota_cancelada' && ctx.motivo) {
            return <p className="text-xs mt-0.5" style={{ color: p.MUTED }}>motivo: {ctx.motivo}</p>;
        }

        if (o.acao === 'nota_movida' && ctx.de) {
            return (
                <p className="text-xs mt-0.5" style={{ color: p.MUTED }}>
                    {FILA[ctx.de] ?? ctx.de} → <strong style={{ color: p.TEXT }}>{FILA[ctx.para] ?? ctx.para}</strong>
                </p>
            );
        }

        return null;
    };

    return (
        <Modal aberto={aberto} onFechar={onFechar} titulo={titulo} p={p}>
            <div className="space-y-3">
                <p className="text-xs" style={{ color: p.MUTED }}>
                    Tudo o que aconteceu com esta nota. O registro não se edita nem se apaga.
                </p>

                <div className="max-h-96 overflow-y-auto pr-1 space-y-2.5">
                    {carregando && (
                        <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>Carregando...</p>
                    )}

                    {!carregando && !erro && itens.length === 0 && (
                        <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>
                            Nenhuma ocorrência registrada.
                        </p>
                    )}

                    {!carregando && itens.map(o => {
                        const c = cor(o.acao, p);
                        const extra = complemento(o);

                        return (
                            <div key={o.id} className="flex gap-2.5">
                                {/* O ponto colorido dá o peso da linha antes da leitura */}
                                <span className="w-2 h-2 rounded-full shrink-0 mt-1.5" style={{ background: c }} />

                                <div className="flex-1 min-w-0">
                                    <div className="flex items-baseline gap-2 flex-wrap">
                                        <span className="text-sm" style={{ color: p.TEXT }}>
                                            <strong>{o.usuario}</strong>{' '}
                                            <span style={{ color: c }}>{FRASE[o.acao] ?? o.acao.replace(/_/g, ' ')}</span>
                                            {extra && <span style={{ color: p.TEXT }}> {extra}</span>}
                                        </span>
                                        <span className="text-xs ml-auto shrink-0" style={{ color: p.MUTED }}>
                                            {quando(o.em)}
                                        </span>
                                    </div>

                                    {edicoes(o)}
                                    {detalhe(o)}
                                </div>
                            </div>
                        );
                    })}
                </div>

                {erro && <p className="text-xs" style={{ color: p.RED }}>{erro}</p>}
            </div>
        </Modal>
    );
}
