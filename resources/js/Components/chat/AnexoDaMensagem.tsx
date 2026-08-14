import { useEffect, useState } from 'react';
import { AnexoMensagem } from '@/types';
import { Palette } from '@/lib/tema';
import { formatarTamanho } from '@/lib/imagem';
import { baixarEGuardar, buscar } from '@/lib/arquivosLocais';
import Icone from '@/Components/painel/Icone';

/**
 * A foto ou o documento dentro da bolha.
 *
 * De onde vem o arquivo, nesta ordem:
 *
 *   1. da cópia local (IndexedDB), se este navegador já guardou uma
 *   2. do servidor — e, ao baixar, a cópia local nasce
 *   3. de lugar nenhum: o prazo venceu e esta máquina nunca abriu o arquivo
 *
 * O caso 3 é o preço combinado de o servidor não guardar arquivo para sempre.
 * A bolha explica em vez de mostrar imagem quebrada.
 */
export default function AnexoDaMensagem({ mensagemId, anexo, minha, p }: {
    mensagemId: number;
    anexo: AnexoMensagem;
    minha: boolean;
    p: Palette;
}) {
    const [url, setUrl] = useState<string | null>(null);
    const [estado, setEstado] = useState<'carregando' | 'pronto' | 'sumiu'>('carregando');

    const url_arquivo = mensagemId > 0 ? route('conversas.mensagens.arquivo', mensagemId) : null;

    useEffect(() => {
        // Bolha otimista (id negativo): o arquivo ainda está subindo
        if (!url_arquivo) { setEstado('carregando'); return; }

        let vivo = true;
        let criada: string | null = null;

        (async () => {
            // 1. a cópia desta máquina
            const local = await buscar(mensagemId);

            if (local) {
                if (!vivo) return;
                criada = URL.createObjectURL(local.blob);
                setUrl(criada);
                setEstado('pronto');
                return;
            }

            // 3. sem cópia local e sem servidor: acabou
            if (!anexo.no_servidor) {
                if (vivo) setEstado('sumiu');
                return;
            }

            // 2. do servidor — e guarda a cópia no caminho
            const blob = await baixarEGuardar(url_arquivo, mensagemId, anexo.nome, anexo.mime);

            if (!vivo) return;

            if (!blob) { setEstado('sumiu'); return; }

            criada = URL.createObjectURL(blob);
            setUrl(criada);
            setEstado('pronto');
        })();

        return () => {
            vivo = false;
            // Sem isto o blob fica preso na memória da aba a cada rolagem da
            // conversa — e são megabytes por foto.
            if (criada) URL.revokeObjectURL(criada);
        };
    }, [mensagemId, anexo.no_servidor, anexo.nome, anexo.mime, url_arquivo]);

    // ── Arquivo que não existe mais em lugar nenhum ───────────────────────────
    if (estado === 'sumiu') {
        return (
            <div className="flex items-center gap-2 rounded-lg px-3 py-2 text-xs"
                style={{ background: p.HOVER_ROW, color: p.MUTED, border: `1px dashed ${p.BORDER}` }}>
                <Icone path="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" className="w-4 h-4 shrink-0" />
                <span>
                    <strong style={{ color: p.TEXT }}>{anexo.nome}</strong>
                    <br />
                    Não está mais no servidor e esta máquina não guardou cópia.
                </span>
            </div>
        );
    }

    // ── Foto ──────────────────────────────────────────────────────────────────
    if (anexo.imagem) {
        return (
            <div className="rounded-lg overflow-hidden" style={{ maxWidth: 220 }}>
                {estado === 'carregando' || !url ? (
                    <div className="flex items-center justify-center h-32 rounded-lg animate-pulse"
                        style={{ background: p.HOVER_ROW, width: 220 }}>
                        <Icone path="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                            className="w-6 h-6" />
                    </div>
                ) : (
                    <a href={url} target="_blank" rel="noreferrer" title={anexo.nome}>
                        <img src={url} alt={anexo.nome}
                            className="block w-full h-auto rounded-lg cursor-zoom-in"
                            style={{ maxHeight: 260, objectFit: 'cover' }} />
                    </a>
                )}
            </div>
        );
    }

    // ── Documento ─────────────────────────────────────────────────────────────
    return (
        <a
            href={url ?? '#'}
            download={anexo.nome}
            onClick={e => { if (!url) e.preventDefault(); }}
            className="flex items-center gap-2.5 rounded-lg px-3 py-2 transition hover:opacity-80"
            style={{
                background: minha ? 'rgba(255,255,255,0.15)' : p.HOVER_ROW,
                color: minha ? '#fff' : p.TEXT,
            }}
        >
            <Icone path="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                className="w-5 h-5 shrink-0" />
            <span className="min-w-0">
                <span className="block text-xs font-medium truncate" style={{ maxWidth: 150 }}>{anexo.nome}</span>
                <span className="block text-[10px] opacity-70">
                    {estado === 'carregando' ? 'baixando…' : formatarTamanho(anexo.tamanho)}
                </span>
            </span>
        </a>
    );
}
