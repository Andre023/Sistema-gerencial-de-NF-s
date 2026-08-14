import { useEffect, useRef, useState } from 'react';
import { PessoaChat } from '@/types';
import { Palette } from '@/lib/tema';
import Avatar from '@/Components/painel/Avatar';
import Icone from '@/Components/painel/Icone';
import Bolha from './Bolha';
import { useChat } from './ChatProvider';

/** Rótulo do papel embaixo do nome — ajuda a saber com quem se está falando. */
const PAPEL: Record<string, string> = {
    recebimento: 'Recebimento',
    pre_lote: 'Pré-lote',
    compras: 'Compras',
    visitante: 'Visitante',
    admin: 'Administrador',
};

/**
 * A conversa aberta dentro da barra lateral.
 *
 * Três faixas fixas, como no WhatsApp: cabeçalho com quem é (e a seta de
 * voltar), o corpo que rola, e o campo de escrever colado embaixo.
 */
export default function PainelConversa({ pessoa, online, meuId, p }: {
    pessoa: PessoaChat;
    online: boolean;
    meuId: number;
    p: Palette;
}) {
    const {
        mensagens, carregandoConversa, temAntigas, lidaPeloOutroAte, enviando, erro,
        fecharConversa, enviar, carregarAntigas, limparErro,
    } = useChat();

    const [texto, setTexto]     = useState('');
    const [arquivo, setArquivo] = useState<File | null>(null);

    const fimRef     = useRef<HTMLDivElement>(null);
    const corpoRef   = useRef<HTMLDivElement>(null);
    const arquivoRef = useRef<HTMLInputElement>(null);

    /*
     * Rola para o fim quando chega mensagem — mas só se a pessoa já estava lá.
     * Quem subiu para reler algo antigo não quer ser puxado de volta a cada
     * mensagem que chega.
     */
    useEffect(() => {
        const corpo = corpoRef.current;
        if (!corpo) return;

        const perto = corpo.scrollHeight - corpo.scrollTop - corpo.clientHeight < 120;

        if (perto || mensagens.length <= 1) {
            fimRef.current?.scrollIntoView({ block: 'end' });
        }
    }, [mensagens]);

    const submeter = async (e: React.FormEvent) => {
        e.preventDefault();
        if (enviando || (!texto.trim() && !arquivo)) return;

        const t = texto;
        const a = arquivo;

        // Limpa antes de a resposta chegar: a bolha otimista já está na tela, e
        // segurar o campo faria a pessoa achar que não enviou.
        setTexto('');
        setArquivo(null);
        if (arquivoRef.current) arquivoRef.current.value = '';

        await enviar(t, a);
    };

    return (
        <div className="flex flex-col h-full min-h-0">

            {/* ── Cabeçalho: quem é, e a volta para a lista ── */}
            <div className="flex items-center gap-2.5 px-3 h-14 shrink-0"
                style={{ borderBottom: `1px solid ${p.BORDER}` }}>

                <button onClick={fecharConversa} title="Voltar para a lista"
                    className="p-1 -ml-1 rounded transition hover:opacity-70 shrink-0"
                    style={{ color: p.MUTED }}>
                    <Icone path="M15 19l-7-7 7-7" className="w-5 h-5" />
                </button>

                <div className="relative shrink-0">
                    <Avatar user={{ name: pessoa.nome, avatar: pessoa.avatar }} size={34} />
                    {online && (
                        <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full"
                            style={{ background: p.GREEN, border: `2px solid ${p.SURFACE}` }} />
                    )}
                </div>

                <div className="min-w-0">
                    <p className="text-sm font-semibold truncate" style={{ color: p.TEXT }}>
                        {pessoa.nome}
                    </p>
                    <p className="text-[11px] truncate" style={{ color: online ? p.GREEN : p.MUTED }}>
                        {online ? 'online' : (PAPEL[pessoa.papel] ?? pessoa.papel)}
                    </p>
                </div>
            </div>

            {/* ── As mensagens ── */}
            <div ref={corpoRef} className="flex-1 min-h-0 overflow-y-auto px-3 py-3 space-y-2">

                {temAntigas && (
                    <button onClick={carregarAntigas}
                        className="mx-auto block text-[11px] px-3 py-1 rounded-full transition hover:opacity-80"
                        style={{ background: p.HOVER_ROW, color: p.MUTED }}>
                        Ver mensagens anteriores
                    </button>
                )}

                {carregandoConversa && (
                    <p className="text-xs text-center py-6" style={{ color: p.MUTED }}>Carregando…</p>
                )}

                {!carregandoConversa && mensagens.length === 0 && (
                    <div className="text-center py-10 px-4">
                        <p className="text-xs" style={{ color: p.MUTED }}>
                            Nenhuma mensagem ainda.
                            <br />
                            Escreva abaixo para começar a conversa com {pessoa.nome.split(' ')[0]}.
                        </p>
                    </div>
                )}

                {mensagens.map(m => (
                    <Bolha
                        key={m.id}
                        mensagem={m}
                        minha={m.autor_id === meuId}
                        lido={m.id > 0 && m.id <= lidaPeloOutroAte}
                        p={p}
                    />
                ))}

                <div ref={fimRef} />
            </div>

            {/* ── Erro de envio ── */}
            {erro && (
                <div className="px-3 py-1.5 text-[11px] flex items-start gap-2"
                    style={{ background: 'rgba(248,81,73,0.12)', color: p.RED }}>
                    <span className="flex-1">{erro}</span>
                    <button onClick={limparErro} className="shrink-0 opacity-70 hover:opacity-100">✕</button>
                </div>
            )}

            {/* ── Escrever ── */}
            <form onSubmit={submeter} className="p-2 shrink-0" style={{ borderTop: `1px solid ${p.BORDER}` }}>

                {arquivo && (
                    <div className="flex items-center gap-2 mb-2 px-2 py-1.5 rounded-lg text-[11px]"
                        style={{ background: p.HOVER_ROW, color: p.TEXT }}>
                        <Icone path="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                            className="w-3.5 h-3.5 shrink-0" />
                        <span className="flex-1 truncate">{arquivo.name}</span>
                        <button type="button" title="Tirar o anexo"
                            onClick={() => {
                                setArquivo(null);
                                if (arquivoRef.current) arquivoRef.current.value = '';
                            }}
                            style={{ color: p.RED }}>✕</button>
                    </div>
                )}

                <div className="flex items-end gap-1.5">
                    <button type="button" title="Anexar foto ou documento"
                        onClick={() => arquivoRef.current?.click()}
                        className="p-2 rounded-lg transition hover:opacity-70 shrink-0"
                        style={{ color: p.MUTED }}>
                        <Icone path="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                            className="w-5 h-5" />
                    </button>

                    <input
                        ref={arquivoRef}
                        type="file"
                        className="hidden"
                        accept="image/jpeg,image/png,image/webp,image/heic,application/pdf"
                        onChange={e => setArquivo(e.target.files?.[0] ?? null)}
                    />

                    <textarea
                        value={texto}
                        onChange={e => setTexto(e.target.value)}
                        rows={1}
                        maxLength={2000}
                        placeholder="Mensagem"
                        // Enter envia, Shift+Enter quebra linha — como no WhatsApp
                        onKeyDown={e => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                submeter(e);
                            }
                        }}
                        className="flex-1 min-w-0 rounded-2xl text-sm px-3 py-2 outline-none resize-none"
                        style={{
                            background: p.INPUT_BG,
                            color: p.TEXT,
                            border: `1px solid ${p.INPUT_BORDER}`,
                            maxHeight: 96,
                        }}
                    />

                    <button type="submit" disabled={enviando || (!texto.trim() && !arquivo)}
                        title="Enviar"
                        className="p-2 rounded-full transition disabled:opacity-30 shrink-0"
                        style={{ background: p.ACCENT, color: '#fff' }}>
                        <Icone path="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" className="w-4 h-4" />
                    </button>
                </div>
            </form>
        </div>
    );
}
