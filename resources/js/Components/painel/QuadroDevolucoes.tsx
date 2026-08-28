import { useEffect, useRef, useState } from 'react';
import { format, parseISO, differenceInCalendarDays } from 'date-fns';
import { Devolucao, DevolucaoAnexo } from '@/types';
import { Palette } from '@/lib/tema';
import { otimizarParaEnvio, formatarTamanho } from '@/lib/imagem';
import Icone from './Icone';
import VisorImagem from './VisorImagem';

const dia = (iso: string | null) => {
    if (!iso) return null;
    try { return format(parseISO(iso), 'dd/MM/yyyy'); } catch { return iso; }
};

const quando = (iso: string) => {
    try { return format(parseISO(iso), "dd/MM 'às' HH:mm"); } catch { return iso; }
};

/**
 * O quadro de devoluções — cartões lado a lado, o print em cima.
 *
 * Substitui um recado que circulava no WhatsApp sempre com a mesma forma: o
 * print do sistema e, embaixo, nota, fornecedor, motivo, quem autorizou e
 * quando o boleto vence. O cartão repete essa ordem de propósito — quem já lia
 * aquilo no grupo não precisa aprender nada.
 *
 * Rola para o lado, e não para baixo: são poucos cartões vivos por vez, e um do
 * lado do outro dá para bater o olho e ver tudo que está em aberto. Empilhado,
 * o quadro empurraria as notas liberadas para fora da tela.
 */
/** O que a fila de notas já sabe e não precisa ser redigitado. */
export interface DadosDaNota {
    numero_nota: string;
    fornecedor: string;
}

export default function QuadroDevolucoes({
    devolucoes, podeUsar, meuNome, onMudou, daNota, onFecharDaNota, p,
}: {
    devolucoes: Devolucao[];
    /** Recebimento e pré-lote abrem e conferem; os demais nem veem o quadro. */
    podeUsar: boolean;
    meuNome: string;
    onMudou: (lista: Devolucao[]) => void;
    /**
     * Nota encaminhada da fila pelo ícone de devolução: abre o formulário com
     * número e fornecedor prontos. null = ninguém encaminhou nada.
     */
    daNota?: DadosDaNota | null;
    onFecharDaNota?: () => void;
    p: Palette;
}) {
    const [lancando, setLancando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);
    const [ocupado, setOcupado] = useState(false);
    const [vendo, setVendo] = useState<{ url: string; nome: string; tamanho: number } | null>(null);

    const abertas = devolucoes.filter(d => !d.conferida_em);
    const conferidas = devolucoes.filter(d => d.conferida_em);

    const agir = async (fn: () => Promise<any>) => {
        setOcupado(true);
        setErro(null);
        try {
            await fn();
        } catch (e: any) {
            // O Laravel devolve `erro` (mensagem pronta) ou `errors` (por campo);
            // o primeiro erro do primeiro campo é o que interessa mostrar.
            const porCampo = Object.values(
                (e?.response?.data?.errors ?? {}) as Record<string, string[]>,
            )[0];

            setErro(e?.response?.data?.erro ?? porCampo?.[0] ?? 'Não foi possível concluir.');
        } finally {
            setOcupado(false);
        }
    };

    const conferir = (d: Devolucao) => agir(async () => {
        const { data } = await window.axios.post(route('devolucoes.conferir', d.id));
        onMudou(devolucoes.map(x => x.id === d.id ? data.devolucao : x));
    });

    const reabrir = (d: Devolucao) => agir(async () => {
        const { data } = await window.axios.post(route('devolucoes.reabrir', d.id));
        onMudou(devolucoes.map(x => x.id === d.id ? data.devolucao : x));
    });

    const excluir = (d: Devolucao) => {
        if (!confirm(`Excluir a devolução da nota ${d.numero_nota}?`)) return;
        agir(async () => {
            await window.axios.delete(route('devolucoes.destroy', d.id));
            onMudou(devolucoes.filter(x => x.id !== d.id));
        });
    };

    return (
        <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>

            <div className="flex items-center justify-between px-5 py-3.5 gap-3 flex-wrap"
                style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.TEXT }}>
                    Devoluções
                    <span className="text-xs font-normal" style={{ color: p.MUTED }}>
                        pré-lote ↔ recebimento
                    </span>
                    <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                        style={{ background: p.ORANGE + '22', color: p.ORANGE, border: `1px solid ${p.ORANGE}33` }}>
                        {abertas.length}
                    </span>
                </h2>

                {podeUsar && (
                    <button onClick={() => setLancando(true)}
                        className="px-3 py-1.5 text-sm font-medium rounded-lg transition"
                        style={{ background: p.ORANGE, color: '#fff' }}>
                        + Nova devolução
                    </button>
                )}
            </div>

            {erro && (
                <div className="px-5 py-2 text-sm" style={{ background: p.RED + '1a', color: p.RED }}>{erro}</div>
            )}

            {devolucoes.length === 0 ? (
                <p className="px-5 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                    Nenhuma devolução em aberto.
                </p>
            ) : (
                /* Rola para o lado: `overflow-x-auto` com os cartões em linha e
                   largura fixa. `items-start` para o cartão baixo não esticar
                   até a altura do mais alto. */
                <div className="flex gap-3 overflow-x-auto items-start p-4 rolagem-x">
                    {[...abertas, ...conferidas].map(d => (
                        <CartaoDevolucao
                            key={d.id}
                            devolucao={d}
                            podeUsar={podeUsar}
                            ocupado={ocupado}
                            onConferir={() => conferir(d)}
                            onReabrir={() => reabrir(d)}
                            onExcluir={() => excluir(d)}
                            onVer={setVendo}
                            p={p}
                        />
                    ))}
                </div>
            )}

            {(lancando || daNota) && (
                <ModalNovaDevolucao
                    // A chave força um formulário novo a cada nota encaminhada:
                    // sem ela, clicar no ícone de outra nota com o modal aberto
                    // manteria os campos da anterior.
                    key={daNota ? `${daNota.numero_nota}-${daNota.fornecedor}` : 'branco'}
                    inicial={daNota ?? null}
                    onFechar={() => { setLancando(false); onFecharDaNota?.(); }}
                    onCriada={nova => {
                        onMudou([nova, ...devolucoes]);
                        setLancando(false);
                        onFecharDaNota?.();
                    }}
                    p={p}
                />
            )}

            {vendo && (
                <VisorImagem
                    url={vendo.url}
                    urlDownload={vendo.url + '?baixar=1'}
                    nome={vendo.nome}
                    tamanho={vendo.tamanho}
                    onFechar={() => setVendo(null)}
                    p={p}
                />
            )}
        </div>
    );
}

// ─── O cartão ─────────────────────────────────────────────────────────────────

function CartaoDevolucao({ devolucao: d, podeUsar, ocupado, onConferir, onReabrir, onExcluir, onVer, p }: {
    devolucao: Devolucao;
    podeUsar: boolean;
    ocupado: boolean;
    onConferir: () => void;
    onReabrir: () => void;
    onExcluir: () => void;
    onVer: (a: { url: string; nome: string; tamanho: number }) => void;
    p: Palette;
}) {
    const conferida = !!d.conferida_em;
    const capa = d.anexos.find(a => a.imagem) ?? null;
    const urlDe = (a: DevolucaoAnexo) => route('devolucoes.arquivo', [d.id, a.id]);

    /*
     * Vencimento: o que faz o card ser urgente ou não.
     *
     * Vermelho quando já venceu ou vence hoje, âmbar até três dias — pintado
     * data a data, logo abaixo. É a única informação do card que muda de peso
     * com o tempo, e a que fazia alguém relendo o grupo perceber tarde demais.
     */

    return (
        <div className="shrink-0 rounded-xl overflow-hidden flex flex-col"
            style={{
                width: 280,
                background: p.BG,
                border: `1px solid ${conferida ? p.BORDER : p.ORANGE + '55'}`,
                opacity: conferida ? 0.72 : 1,
            }}>

            {/* ── O print, em cima ── */}
            {capa ? (
                <button type="button"
                    onClick={() => onVer({ url: urlDe(capa), nome: capa.nome, tamanho: capa.tamanho })}
                    className="block w-full"
                    title="Ver o print">
                    <img src={urlDe(capa)} alt={capa.nome}
                        className="block w-full cursor-zoom-in"
                        style={{ height: 150, objectFit: 'cover', objectPosition: 'top' }} />
                </button>
            ) : (
                <div className="flex items-center justify-center" style={{ height: 150, background: p.HOVER_ROW }}>
                    <Icone path="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                        className="w-8 h-8" />
                </div>
            )}

            {/* Os demais arquivos, em miniatura */}
            {d.anexos.length > 1 && (
                <div className="flex gap-1 px-2 py-1.5 overflow-x-auto rolagem-x"
                    style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                    {d.anexos.filter(a => a.id !== capa?.id).map(a => (
                        a.imagem ? (
                            <button key={a.id} type="button" title={a.nome}
                                onClick={() => onVer({ url: urlDe(a), nome: a.nome, tamanho: a.tamanho })}>
                                <img src={urlDe(a)} alt={a.nome}
                                    className="rounded cursor-zoom-in shrink-0"
                                    style={{ width: 40, height: 40, objectFit: 'cover' }} />
                            </button>
                        ) : (
                            <a key={a.id} href={urlDe(a)} target="_blank" rel="noreferrer" title={a.nome}
                                className="flex items-center justify-center rounded shrink-0"
                                style={{ width: 40, height: 40, background: p.HOVER_ROW, color: p.RED }}>
                                <Icone path="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                    className="w-4 h-4" />
                            </a>
                        )
                    ))}
                </div>
            )}

            {/* ── Os campos, na ordem do recado que isto substitui ── */}
            <div className="p-3 space-y-1.5 flex-1">
                {/* O título diz de cara se há boleto para cobrar.
                    Roxo, e não vermelho ou âmbar, de propósito: logo abaixo as
                    datas de vencimento usam essa escala para urgência, e repetir
                    a cor aqui faria "sem boleto" parecer atrasado — quando é
                    justamente o card que não tem prazo nenhum a vencer. */}
                <p className="text-[11px] font-bold uppercase tracking-wide"
                    style={{ color: d.sem_boleto ? p.PURPLE : p.ORANGE }}>
                    Fazer devolução {d.sem_boleto ? 'sem boleto' : 'com boleto'}
                </p>

                <Campo rotulo="Nota" valor={d.numero_nota} p={p} forte />
                <Campo rotulo="Fornecedor" valor={d.fornecedor} p={p} />
                <Campo rotulo="Motivo" valor={d.motivo} p={p} />
                <Campo rotulo="Autorizado por" valor={d.autorizado_por} p={p} />

                {d.boletos_vencem.length > 0 && (
                    <p className="text-xs flex gap-1.5 flex-wrap">
                        <span style={{ color: p.MUTED }}>
                            {d.boletos_vencem.length > 1 ? 'Boletos vencem:' : 'Boleto vence:'}
                        </span>
                        {d.boletos_vencem.map(data => {
                            // Cada data se pinta sozinha: numa nota parcelada a
                            // primeira pode estar vencida e a última longe, e uma
                            // cor só para todas esconderia justamente a urgente.
                            const dias = differenceInCalendarDays(parseISO(data), new Date());
                            const cor = dias <= 0 ? p.RED : dias <= 3 ? p.AMBER : p.TEXT;

                            return (
                                <strong key={data} style={{ color: cor }} title={dias <= 0 ? 'Vencido' : `em ${dias} dia(s)`}>
                                    {dia(data)}{dias <= 0 && ' (vencido)'}
                                </strong>
                            );
                        })}
                    </p>
                )}
            </div>

            {/* ── Rodapé: quem lançou, e o que fazer ── */}
            <div className="px-3 py-2 space-y-2" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                <p className="text-[10px]" style={{ color: p.MUTED }}>
                    {d.criada_por ?? 'removido'} · {quando(d.created_at)}
                </p>

                {conferida ? (
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-[11px] font-medium flex items-center gap-1" style={{ color: p.GREEN }}>
                            <Icone path="M5 13l4 4L19 7" className="w-3.5 h-3.5" />
                            {d.conferida_por ?? 'conferida'}
                        </span>
                        {podeUsar && (
                            <button onClick={onReabrir} disabled={ocupado}
                                className="text-[11px] disabled:opacity-40" style={{ color: p.MUTED }}>
                                reabrir
                            </button>
                        )}
                    </div>
                ) : podeUsar && (
                    <div className="flex items-center gap-1.5">
                        <button onClick={onConferir} disabled={ocupado}
                            className="flex-1 px-2 py-1.5 text-xs font-medium rounded-md transition disabled:opacity-40"
                            style={{ background: p.GREEN + '1a', color: p.GREEN, border: `1px solid ${p.GREEN}44` }}>
                            Conferido ✓
                        </button>
                        <button onClick={onExcluir} disabled={ocupado} title="Excluir"
                            className="px-2 py-1.5 rounded-md transition disabled:opacity-40"
                            style={{ color: p.RED }}>
                            <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                className="w-3.5 h-3.5" />
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

function Campo({ rotulo, valor, p, forte }: { rotulo: string; valor: string; p: Palette; forte?: boolean }) {
    return (
        <p className="text-xs flex gap-1.5">
            <span className="shrink-0" style={{ color: p.MUTED }}>{rotulo}:</span>
            <strong className="min-w-0 break-words" style={{ color: p.TEXT, fontWeight: forte ? 700 : 500 }}>
                {valor}
            </strong>
        </p>
    );
}

// ─── Lançar ───────────────────────────────────────────────────────────────────

/**
 * O formulário de lançamento.
 *
 * O print é OBRIGATÓRIO, e o botão fica travado sem ele: é a peça que dá
 * contexto ao recado. Sem essa trava o quadro voltaria a ter bilhete sem prova,
 * que é o que já acontecia no grupo quando alguém digitava com pressa.
 */
function ModalNovaDevolucao({ inicial, onFechar, onCriada, p }: {
    /** Nota e fornecedor vindos da fila — null quando é lançamento do zero. */
    inicial?: DadosDaNota | null;
    onFechar: () => void;
    onCriada: (d: Devolucao) => void;
    p: Palette;
}) {
    const [fornecedor, setFornecedor] = useState(inicial?.fornecedor ?? '');
    const [numeroNota, setNumeroNota] = useState(inicial?.numero_nota ?? '');
    const [motivo, setMotivo] = useState('');
    const [autorizadoPor, setAutorizadoPor] = useState('');
    /* Uma nota grande sai parcelada: a lista começa com um campo vazio e
       cresce conforme a pessoa acrescenta vencimentos. */
    const [vencimentos, setVencimentos] = useState<string[]>(['']);
    /** Não haverá boleto. Diferente de "ainda não saiu" — ver o model Devolucao. */
    const [semBoleto, setSemBoleto] = useState(false);
    const [arquivos, setArquivos] = useState<File[]>([]);
    const [previas, setPrevias] = useState<string[]>([]);
    const [erro, setErro] = useState<string | null>(null);
    const [enviando, setEnviando] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    // Miniaturas do que está para subir — sem elas, quem colou dois prints não
    // sabe se pegou os certos antes de mandar.
    useEffect(() => {
        const urls = arquivos.filter(a => a.type.startsWith('image/')).map(a => URL.createObjectURL(a));
        setPrevias(urls);

        return () => urls.forEach(u => URL.revokeObjectURL(u));
    }, [arquivos]);

    const juntar = (novos: File[]) => {
        if (!novos.length) return;
        setArquivos(a => [...a, ...novos].slice(0, 10));
        setErro(null);
    };

    // Ctrl+V: o caminho mais curto — tirou o print, colou. Mesmo atalho que os
    // anexos da nota já têm.
    useEffect(() => {
        const aoColar = (e: ClipboardEvent) => {
            const alvo = e.target;
            if (alvo instanceof Element && alvo.closest('input, textarea')) return;

            const dados = Array.from(e.clipboardData?.items ?? [])
                .filter(i => i.kind === 'file')
                .map(i => i.getAsFile())
                .filter((f): f is File => f !== null);

            if (!dados.length) return;
            e.preventDefault();
            juntar(dados);
        };

        document.addEventListener('paste', aoColar);

        return () => document.removeEventListener('paste', aoColar);
    }, []);

    const enviar = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!arquivos.length || enviando) return;

        setEnviando(true);
        setErro(null);

        try {
            const corpo = new FormData();
            corpo.append('fornecedor', fornecedor.trim());
            corpo.append('numero_nota', numeroNota.trim());
            corpo.append('motivo', motivo.trim());
            corpo.append('autorizado_por', autorizadoPor.trim());
            // Só as preenchidas: o campo em branco é o convite para digitar,
            // não uma data vazia para o servidor recusar.
            corpo.append('sem_boleto', semBoleto ? '1' : '0');
            // Sem boleto não manda data: o servidor descartaria de qualquer
            // forma, e mandar as duas coisas é o pedido se contradizendo.
            if (!semBoleto) {
                vencimentos.filter(Boolean).forEach(v => corpo.append('boletos_vencem[]', v));
            }

            // Reduz e converte para WebP no aparelho: a VM tem 1 GB e não abre
            // print de celular sem risco (ver lib/imagem).
            for (const bruto of arquivos) {
                const { arquivo } = await otimizarParaEnvio(bruto);
                corpo.append('arquivos[]', arquivo);
            }

            const { data } = await window.axios.post(route('devolucoes.store'), corpo);
            onCriada(data.devolucao);
        } catch (err: any) {
            const erros = err?.response?.data?.errors;
            setErro(erros ? (Object.values(erros)[0] as string[])[0] : 'Não foi possível lançar a devolução.');
        } finally {
            setEnviando(false);
        }
    };

    const completo = fornecedor.trim() && numeroNota.trim() && motivo.trim()
        && autorizadoPor.trim() && arquivos.length > 0;

    const campo = (rotulo: string, valor: string, set: (v: string) => void, tipo = 'text', foco = false) => (
        <label className="block">
            <span className="block text-xs mb-1" style={{ color: p.MUTED }}>{rotulo}</span>
            <input type={tipo} value={valor} onChange={e => set(e.target.value)} maxLength={255}
                autoFocus={foco}
                className="w-full rounded-lg text-sm px-3 py-2 outline-none"
                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
        </label>
    );

    return (
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm touch-none" onClick={onFechar} />

            <form onSubmit={enviar}
                className="relative rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-lg flex flex-col max-h-[92dvh]"
                style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>

                <div className="flex items-center justify-between px-5 pt-5 pb-4 shrink-0"
                    style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                    <h3 className="text-sm font-semibold" style={{ color: p.TEXT }}>
                        {inicial ? `Devolução da nota ${inicial.numero_nota}` : 'Nova devolução'}
                    </h3>
                    <button type="button" onClick={onFechar} style={{ color: p.MUTED }}>
                        <Icone path="M6 18L18 6M6 6l12 12" />
                    </button>
                </div>

                <div className="px-5 py-4 space-y-3 overflow-y-auto">

                    {inicial && (
                        <p className="text-[11px] px-2.5 py-1.5 rounded-lg"
                            style={{ background: p.ORANGE + '14', color: p.ORANGE, border: `1px solid ${p.ORANGE}33` }}>
                            Nota e fornecedor vieram da fila. Falta o print e o resto.
                        </p>
                    )}

                    {/* O print vem primeiro no formulário porque vem primeiro no
                        cartão — e porque é o que trava o envio. */}
                    <div>
                        <span className="block text-xs mb-1" style={{ color: p.MUTED }}>
                            Print ou PDF <strong style={{ color: p.RED }}>*</strong>
                        </span>

                        <input ref={inputRef} type="file" multiple className="hidden"
                            accept="image/jpeg,image/png,image/webp,image/heic,application/pdf"
                            onChange={e => juntar(Array.from(e.target.files ?? []))} />

                        <button type="button" onClick={() => inputRef.current?.click()}
                            className="w-full px-3 py-3 text-sm rounded-lg transition"
                            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px dashed ${p.INPUT_BORDER}` }}>
                            {arquivos.length
                                ? `${arquivos.length} arquivo(s) — clique para juntar mais`
                                : 'Escolher arquivo, ou colar com Ctrl+V'}
                        </button>

                        {arquivos.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 mt-2">
                                {previas.map((u, i) => (
                                    <img key={i} src={u} alt=""
                                        className="rounded" style={{ width: 52, height: 52, objectFit: 'cover' }} />
                                ))}
                                {arquivos.filter(a => !a.type.startsWith('image/')).map((a, i) => (
                                    <span key={i} className="flex items-center px-2 rounded text-[10px]"
                                        style={{ background: p.HOVER_ROW, color: p.TEXT, height: 52 }}>
                                        {a.name}
                                    </span>
                                ))}
                                <button type="button" onClick={() => setArquivos([])}
                                    className="text-[11px] self-center px-2" style={{ color: p.RED }}>
                                    limpar
                                </button>
                            </div>
                        )}

                        <p className="text-[11px] mt-1" style={{ color: p.MUTED }}>
                            {formatarTamanho(arquivos.reduce((s, a) => s + a.size, 0))} — as fotos são reduzidas antes de subir.
                        </p>
                    </div>

                    {campo('Nota', numeroNota, setNumeroNota)}
                    {campo('Fornecedor', fornecedor, setFornecedor)}
                    {/* Foco no Motivo quando a nota veio da fila: nota e
                        fornecedor já estão preenchidos, e este é o primeiro
                        campo que ainda pede digitação. */}
                    {campo('Motivo', motivo, setMotivo, 'text', !!inicial)}
                    {campo('Autorizado por', autorizadoPor, setAutorizadoPor)}

                    {/* A marca vem ANTES das datas: marcada, não há data que
                        preencher, e deixar os campos à mostra convidaria a
                        digitar algo que seria descartado no envio. */}
                    <label className="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" checked={semBoleto}
                            onChange={e => setSemBoleto(e.target.checked)}
                            style={{ accentColor: p.PURPLE }} />
                        <span className="text-sm" style={{ color: semBoleto ? p.PURPLE : p.MUTED }}>
                            Sem boleto
                        </span>
                    </label>

                    <div hidden={semBoleto}>
                        <span className="block text-xs mb-1" style={{ color: p.MUTED }}>
                            Vencimento do boleto
                            <span className="ml-1 opacity-70">(pode ter mais de um)</span>
                        </span>

                        <div className="space-y-1.5">
                            {vencimentos.map((v, i) => (
                                <div key={i} className="flex items-center gap-1.5">
                                    <input type="date" value={v}
                                        onChange={e => setVencimentos(l => l.map((x, j) => j === i ? e.target.value : x))}
                                        className="flex-1 rounded-lg text-sm px-3 py-2 outline-none"
                                        style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />

                                    {/* O primeiro campo não some: sem ele a pessoa
                                        ficaria sem onde digitar a primeira data. */}
                                    {vencimentos.length > 1 && (
                                        <button type="button" title="Tirar este vencimento"
                                            onClick={() => setVencimentos(l => l.filter((_, j) => j !== i))}
                                            className="px-2 py-2 rounded-lg" style={{ color: p.RED }}>
                                            <Icone path="M6 18L18 6M6 6l12 12" className="w-4 h-4" />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>

                        <button type="button" onClick={() => setVencimentos(l => [...l, ''])}
                            className="mt-1.5 text-xs font-medium px-2 py-1 rounded-md transition"
                            style={{ color: p.ACCENT, background: p.ACCENT + '14' }}>
                            + Outro vencimento
                        </button>
                    </div>

                    {erro && <p className="text-xs" style={{ color: p.RED }}>{erro}</p>}
                </div>

                <div className="px-5 py-4 shrink-0" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                    <button type="submit" disabled={!completo || enviando}
                        title={arquivos.length ? '' : 'Anexe o print antes de lançar'}
                        className="w-full px-4 py-2.5 text-sm font-medium text-white rounded-lg transition disabled:opacity-40"
                        style={{ background: p.ORANGE }}>
                        {enviando ? 'Lançando...' : 'Lançar devolução'}
                    </button>
                </div>
            </form>
        </div>
    );
}
