import React, { useState, useEffect, useCallback, useRef } from 'react';
import { format, parseISO } from 'date-fns';
import { Palette } from '@/lib/tema';
import Modal from './Modal';
import Icone from './Icone';

/**
 * Um comentário — e só.
 *
 * Esta thread já misturava eventos deduzidos ("abriu custo", "corrigiu
 * custo") com o que as pessoas escreviam. O histórico saiu daqui e virou o
 * livro de ocorrências, que registra na hora da ação: aqui ficou a conversa.
 */
export interface ItemComentario {
    id: number;
    texto: string;
    usuario: string;
    usuario_id: number | null;
    em: string;
    pode_excluir: boolean;
}

const quando = (iso: string) => {
    try { return format(parseISO(iso), "dd/MM/yyyy 'às' HH:mm"); } catch { return iso; }
};

const iniciais = (nome: string) => nome.trim().slice(0, 2).toUpperCase();

export default function ModalComentarios({ aberto, onFechar, baseUrl, titulo, onMudou, recarregarToken, podeComentar = true, p }: {
    aberto: boolean;
    onFechar: () => void;
    /** Ex.: "/requisicoes/12/comentarios" */
    baseUrl: string | null;
    titulo: string;
    /** Chamado quando a thread muda, para a lista atualizar o contador. */
    onMudou?: () => void;
    /** Muda de valor quando chega evento do Echo — refaz a busca (comentário de outro usuário). */
    recarregarToken?: number;
    /** Quando false (visitante), a thread é só leitura — sem campo de postar. */
    podeComentar?: boolean;
    p: Palette;
}) {
    const [comentarios, setComentarios] = useState<ItemComentario[]>([]);
    const [texto, setTexto] = useState('');
    const [carregando, setCarregando] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);
    const fimRef = useRef<HTMLDivElement>(null);

    const buscar = useCallback(async () => {
        if (!baseUrl) return;
        setCarregando(true);
        setErro(null);
        try {
            const { data } = await window.axios.get(baseUrl);
            setComentarios(data.comentarios);
        } catch {
            setErro('Não foi possível carregar a conversa.');
        } finally {
            setCarregando(false);
        }
    }, [baseUrl]);

    useEffect(() => {
        if (aberto) { setTexto(''); buscar(); }
    }, [aberto, buscar]);

    // Alguém comentou/alterou em outra máquina — recarrega a thread aberta
    useEffect(() => {
        if (aberto && recarregarToken !== undefined) buscar();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [recarregarToken]);

    // Rola para o fim quando a thread cresce
    useEffect(() => {
        if (aberto && comentarios.length) fimRef.current?.scrollIntoView({ block: 'end' });
    }, [comentarios, aberto]);

    const enviar = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!baseUrl || !texto.trim()) return;
        setEnviando(true);
        setErro(null);
        try {
            const { data } = await window.axios.post(baseUrl, { texto: texto.trim() });
            setComentarios(data.comentarios);
            setTexto('');
            onMudou?.();
        } catch (err: any) {
            setErro(err?.response?.data?.message ?? 'Não foi possível enviar o comentário.');
        } finally {
            setEnviando(false);
        }
    };

    const excluir = async (id: number) => {
        if (!baseUrl || !confirm('Excluir este comentário?')) return;
        try {
            const { data } = await window.axios.delete(`${baseUrl}/${id}`);
            setComentarios(data.comentarios);
            onMudou?.();
        } catch {
            setErro('Não foi possível excluir o comentário.');
        }
    };

    return (
        <Modal aberto={aberto} onFechar={onFechar} titulo={titulo} p={p}>
            <div className="space-y-4">

                {/* ── Linha do tempo ── */}
                <div className="max-h-80 overflow-y-auto pr-1 space-y-3">
                    {carregando && (
                        <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>Carregando...</p>
                    )}

                    {!carregando && comentarios.length === 0 && (
                        <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>
                            Nenhum comentário ainda.
                            <span className="block text-xs mt-1">
                                O histórico da nota está em Ocorrências.
                            </span>
                        </p>
                    )}

                    {!carregando && comentarios.map(item => (
                        <div key={item.id} className="flex gap-2.5 group">
                            <span className="w-7 h-7 rounded-full shrink-0 flex items-center justify-center text-[10px] font-bold text-white"
                                style={{ background: p.ACCENT }}>
                                {iniciais(item.usuario)}
                            </span>
                            <div className="flex-1 min-w-0">
                                <div className="flex items-baseline gap-2">
                                    <span className="text-sm font-medium" style={{ color: p.TEXT }}>{item.usuario}</span>
                                    <span className="text-xs" style={{ color: p.MUTED }}>{quando(item.em)}</span>
                                    {item.pode_excluir && (
                                        <button onClick={() => excluir(item.id)} title="Excluir comentário"
                                            className="ml-auto acoes-hover p-1.5 -m-1 rounded"
                                            style={{ color: p.RED }}>
                                            <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" />
                                        </button>
                                    )}
                                </div>
                                <p className="text-sm whitespace-pre-wrap break-words rounded-lg px-3 py-2 mt-1"
                                    style={{ background: p.HOVER_ROW, color: p.TEXT }}>
                                    {item.texto}
                                </p>
                            </div>
                        </div>
                    ))}
                    <div ref={fimRef} />
                </div>

                {erro && <p className="text-xs" style={{ color: p.RED }}>{erro}</p>}

                {/* ── Novo comentário (escondido para o visitante) ── */}
                {podeComentar ? (
                    <form onSubmit={enviar} className="space-y-2 pt-3" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                        <textarea value={texto} onChange={e => setTexto(e.target.value)} rows={2} maxLength={1000}
                            placeholder="Escreva um comentário..."
                            onKeyDown={e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) enviar(e); }}
                            className="block w-full rounded-lg text-sm px-3 py-2 outline-none resize-none"
                            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
                        <div className="flex items-center justify-between">
                            <span className="text-xs" style={{ color: p.MUTED }}>Ctrl+Enter para enviar</span>
                            <button type="submit" disabled={enviando || !texto.trim()}
                                className="px-4 py-1.5 text-sm font-medium text-white rounded-lg transition disabled:opacity-40"
                                style={{ background: p.ACCENT }}>
                                {enviando ? 'Enviando...' : 'Comentar'}
                            </button>
                        </div>
                    </form>
                ) : (
                    <p className="text-xs text-center pt-3" style={{ borderTop: `1px solid ${p.BORDER}`, color: p.MUTED }}>
                        Somente leitura
                    </p>
                )}
            </div>
        </Modal>
    );
}
