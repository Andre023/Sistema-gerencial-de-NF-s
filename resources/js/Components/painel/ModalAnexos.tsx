import React, { useState, useEffect, useCallback, useRef } from 'react';
import { format, parseISO } from 'date-fns';
import { Palette } from '@/lib/tema';
import { Anexo } from '@/types';
import { otimizarParaEnvio, formatarTamanho } from '@/lib/imagem';
import Modal from './Modal';
import Icone from './Icone';

const quando = (iso: string) => {
    try { return format(parseISO(iso), "dd/MM 'às' HH:mm"); } catch { return iso; }
};

/**
 * Documentos e fotos da nota.
 *
 * A foto é reduzida e convertida para WebP AQUI, antes de sair do aparelho —
 * a VM tem 1 GB e não aguentaria decodificar imagem de celular (ver lib/imagem).
 * O arquivo nunca vira URL pública: some e download passam pela rota autenticada.
 */
export default function ModalAnexos({ aberto, onFechar, baseUrl, titulo, onMudou, podeAnexar, p }: {
    aberto: boolean;
    onFechar: () => void;
    /** Ex.: "/notas/12/anexos" */
    baseUrl: string | null;
    titulo: string;
    /** Chamado quando a lista muda, para a fila atualizar o contador. */
    onMudou?: () => void;
    /** Recebimento e pré-lote enviam; os demais só veem. */
    podeAnexar: boolean;
    p: Palette;
}) {
    const [anexos, setAnexos] = useState<Anexo[]>([]);
    const [carregando, setCarregando] = useState(false);
    const [enviando, setEnviando] = useState(false);
    const [progresso, setProgresso] = useState<string | null>(null);
    const [erro, setErro] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const buscar = useCallback(async () => {
        if (!baseUrl) return;
        setCarregando(true);
        setErro(null);
        try {
            const { data } = await window.axios.get(baseUrl);
            setAnexos(data.anexos);
        } catch {
            setErro('Não foi possível carregar os anexos.');
        } finally {
            setCarregando(false);
        }
    }, [baseUrl]);

    useEffect(() => {
        if (aberto) { setErro(null); setProgresso(null); buscar(); }
    }, [aberto, buscar]);

    const enviar = async (lista: FileList | null) => {
        if (!baseUrl || !lista?.length) return;

        setEnviando(true);
        setErro(null);

        // Um a um: o servidor tem 6 processos PHP no total, e mandar tudo de
        // uma vez ocuparia todos enquanto o resto da equipe usa o sistema.
        for (let i = 0; i < lista.length; i++) {
            const bruto = lista[i];
            const vez = lista.length > 1 ? ` (${i + 1}/${lista.length})` : '';

            try {
                setProgresso(`Preparando ${bruto.name}${vez}...`);
                const { arquivo, convertido, tamanhoOriginal } = await otimizarParaEnvio(bruto);

                setProgresso(
                    convertido
                        ? `Enviando ${formatarTamanho(arquivo.size)}${vez} — era ${formatarTamanho(tamanhoOriginal)}`
                        : `Enviando ${formatarTamanho(arquivo.size)}${vez}...`,
                );

                const form = new FormData();
                form.append('arquivo', arquivo);

                const { data } = await window.axios.post(baseUrl, form);
                setAnexos(atual => [...atual, data.anexo]);
                onMudou?.();
            } catch (e: any) {
                const msg = e?.response?.data?.errors?.arquivo?.[0]
                    ?? e?.response?.data?.erro
                    ?? (e?.response?.status === 413
                        ? 'Arquivo grande demais para o servidor.'
                        : `Falha ao enviar ${bruto.name}.`);
                setErro(msg);
                break; // para na primeira falha em vez de repetir o mesmo erro
            }
        }

        setEnviando(false);
        setProgresso(null);
        if (inputRef.current) inputRef.current.value = ''; // permite reenviar o mesmo arquivo
    };

    const remover = async (anexo: Anexo) => {
        if (!baseUrl) return;
        setErro(null);
        try {
            await window.axios.delete(`${baseUrl}/${anexo.id}`);
            setAnexos(atual => atual.filter(a => a.id !== anexo.id));
            onMudou?.();
        } catch {
            setErro('Não foi possível remover o anexo.');
        }
    };

    return (
        <Modal aberto={aberto} onFechar={onFechar} titulo={`Anexos — ${titulo}`} p={p}>
            <div className="space-y-4">
                {podeAnexar && (
                    <div>
                        <input
                            ref={inputRef}
                            type="file"
                            multiple
                            accept="image/jpeg,image/png,image/webp,image/heic,application/pdf"
                            className="hidden"
                            onChange={e => enviar(e.target.files)}
                        />
                        <button
                            type="button"
                            onClick={() => inputRef.current?.click()}
                            disabled={enviando}
                            className="w-full flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium rounded-lg transition disabled:opacity-50"
                            style={{ background: p.ACCENT, color: '#fff' }}>
                            <Icone path="M12 4v16m8-8H4" />
                            {enviando ? 'Enviando...' : 'Enviar documento ou foto'}
                        </button>

                        {progresso && (
                            <p className="mt-2 text-xs text-center" style={{ color: p.MUTED }}>{progresso}</p>
                        )}

                        <p className="mt-2 text-xs text-center" style={{ color: p.MUTED }}>
                            JPG, PNG, WebP, HEIC ou PDF. As fotos são reduzidas no aparelho antes de subir.
                        </p>
                    </div>
                )}

                {erro && (
                    <div className="px-3 py-2 text-sm rounded-lg"
                        style={{ background: p.RED + '1a', color: p.RED }}>
                        {erro}
                    </div>
                )}

                <div className="space-y-2 max-h-80 overflow-y-auto">
                    {carregando && <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>Carregando...</p>}

                    {!carregando && anexos.length === 0 && (
                        <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>
                            Nenhum documento nesta nota.
                        </p>
                    )}

                    {anexos.map(anexo => (
                        <div key={anexo.id}
                            className="flex items-center gap-3 px-3 py-2 rounded-lg"
                            style={{ background: p.INPUT_BG, border: `1px solid ${p.BORDER}` }}>

                            <span style={{ color: anexo.imagem ? p.ACCENT : p.RED }}>
                                <Icone path={anexo.imagem
                                    ? 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'
                                    : 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z'} />
                            </span>

                            <div className="min-w-0 flex-1">
                                <p className="text-sm truncate" style={{ color: p.TEXT }}>{anexo.nome}</p>
                                <p className="text-xs" style={{ color: p.MUTED }}>
                                    {formatarTamanho(anexo.tamanho)}
                                    {anexo.enviado_por && ` · ${anexo.enviado_por.split(' ')[0]}`}
                                    {' · '}{quando(anexo.created_at)}
                                </p>
                            </div>

                            {/* Link normal: o navegador cuida de abrir ou baixar.
                                A rota exige sessão, então isso não vaza nada. */}
                            <a href={`${baseUrl}/${anexo.id}`}
                                target="_blank" rel="noopener noreferrer"
                                title={anexo.imagem ? 'Abrir' : 'Baixar'}
                                className="p-2 rounded-lg transition"
                                style={{ color: p.ACCENT }}>
                                <Icone path={anexo.imagem
                                    ? 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z'
                                    : 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4'} />
                            </a>

                            {podeAnexar && (
                                <button type="button" onClick={() => remover(anexo)} title="Remover"
                                    className="p-2 rounded-lg transition" style={{ color: p.MUTED }}
                                    onMouseEnter={e => (e.currentTarget.style.color = p.RED)}
                                    onMouseLeave={e => (e.currentTarget.style.color = p.MUTED)}>
                                    <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </button>
                            )}
                        </div>
                    ))}
                </div>

                <p className="text-xs" style={{ color: p.MUTED }}>
                    Os arquivos são apagados 2 dias depois de a nota ser liberada — e na hora,
                    se ela for cancelada ou excluída.
                </p>
            </div>
        </Modal>
    );
}
