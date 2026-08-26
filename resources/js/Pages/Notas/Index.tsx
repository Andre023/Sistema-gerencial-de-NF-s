import React, { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { format, parseISO, addDays, subDays } from 'date-fns';
import { Devolucao, Nota, Card, Fornecedor, FiltrosAtivos, OpcoesSistema, Nivel, ResumoAlertas, ResumoTipos, Permissoes, TipoCard, OrigemNota } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette, lojaNome, hoje, nivelCor, NIVEL_LABEL, idadeTexto, TIPO_CARD_LABEL, STATUS_NOTA_LABEL, CARD_COR_DARK, CARD_COR_LIGHT, ORIGEM_LABEL } from '@/lib/tema';
import Icone from '@/Components/painel/Icone';
import Modal from '@/Components/painel/Modal';
import THead from '@/Components/painel/THead';
import CampoFornecedor from '@/Components/painel/CampoFornecedor';
import CardBadge from '@/Components/painel/CardBadge';
import ModalComentarios from '@/Components/painel/ModalComentarios';
import ModalAnexos from '@/Components/painel/ModalAnexos';
import QuadroDevolucoes from '@/Components/painel/QuadroDevolucoes';
import Avatar from '@/Components/painel/Avatar';

interface Props {
    recebimento: Nota[];
    preLote: Nota[];
    liberadas: Nota[];
    canceladas: Nota[];
    devolucoes: Devolucao[];
    fornecedores: Fornecedor[];
    dataFiltro: string;
    resumoAlertas: ResumoAlertas;
    resumoTipos: ResumoTipos;
    totalReconferir: number;
    filtros: FiltrosAtivos;
    opcoes: OpcoesSistema;
}

// ─── Formulário de nota ─────────────────────────────────────────────────────────

interface DadosForm {
    numero_nota: string; fornecedor_id: number | '';
    fornecedor: { id: number | ''; nome: string };
    fornecedor_novo: boolean; fornecedor_nome: string;
    loja: number | ''; origem: string; ceasa: number; observacao: string;
}

function FormNota({ fornecedores, opcoes, inicial, origemDefault, onSubmit, onCancelar, carregando, erros, labelSubmit, p }: {
    fornecedores: Fornecedor[]; opcoes: OpcoesSistema; inicial?: Nota; origemDefault: string;
    onSubmit: (d: Omit<DadosForm, 'fornecedor'>) => void; onCancelar: () => void;
    carregando: boolean; erros: Record<string, string>; labelSubmit: string; p: Palette;
}) {
    const [form, setForm] = useState<DadosForm>({
        numero_nota: inicial?.numero_nota ?? '', fornecedor_id: inicial?.fornecedor?.id ?? '',
        fornecedor: { id: inicial?.fornecedor?.id ?? '', nome: inicial?.fornecedor?.nome ?? '' },
        fornecedor_novo: false, fornecedor_nome: '', // checkbox sempre começa desmarcado
        loja: inicial?.loja ?? '', origem: inicial?.origem ?? origemDefault,
        ceasa: inicial?.ceasa ?? 0, observacao: inicial?.observacao ?? '',
    });

    const set = <K extends keyof DadosForm>(k: K, v: DadosForm[K]) => setForm(prev => ({ ...prev, [k]: v }));

    const inputStyle = (hasErr?: boolean) => ({
        background: p.INPUT_BG, color: p.TEXT,
        border: `1px solid ${hasErr ? p.RED : p.INPUT_BORDER}`,
    });

    const campo = (label: string, obrigatorio: boolean, children: React.ReactNode, erro?: string) => (
        <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>
                {label}{obrigatorio && <span style={{ color: p.RED }} className="ml-0.5">*</span>}
            </label>
            {children}
            {erro && <p className="text-xs mt-1" style={{ color: p.RED }}>{erro}</p>}
        </div>
    );

    return (
        <form onSubmit={e => { e.preventDefault(); onSubmit({
                numero_nota: form.numero_nota,
                fornecedor_id: form.fornecedor_novo ? '' : form.fornecedor.id,
                fornecedor_novo: form.fornecedor_novo,
                fornecedor_nome: form.fornecedor_novo ? form.fornecedor_nome : '',
                loja: form.loja, origem: form.origem, ceasa: form.ceasa, observacao: form.observacao,
            }); }}
            className="space-y-4">
            {campo('Número da nota', true,
                <input type="text" value={form.numero_nota} onChange={e => set('numero_nota', e.target.value)}
                    placeholder="Ex: 46252"
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle(!!erros.numero_nota)} />, erros.numero_nota
            )}
            <div>
                <label className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>
                    Fornecedor<span style={{ color: p.RED }} className="ml-0.5">*</span>
                </label>
                {form.fornecedor_novo ? (
                    <>
                        <input type="text" value={form.fornecedor_nome} autoComplete="off"
                            onChange={e => set('fornecedor_nome', e.target.value)}
                            placeholder="Nome do novo fornecedor"
                            className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                            style={inputStyle(!!erros.fornecedor_nome)} />
                        {erros.fornecedor_nome && <p className="text-xs mt-1" style={{ color: p.RED }}>{erros.fornecedor_nome}</p>}
                    </>
                ) : (
                    <CampoFornecedor fornecedores={fornecedores} valor={form.fornecedor}
                        onChange={v => setForm(prev => ({ ...prev, fornecedor: v, fornecedor_id: v.id }))}
                        erro={erros.fornecedor_id} p={p} />
                )}
                <label className="flex items-center gap-2 mt-2 cursor-pointer select-none">
                    <input type="checkbox" checked={form.fornecedor_novo}
                        onChange={e => set('fornecedor_novo', e.target.checked)}
                        style={{ accentColor: p.ACCENT }} />
                    <span className="text-sm" style={{ color: p.MUTED }}>Fornecedor novo — cadastra ao lançar</span>
                </label>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {campo('Loja', true,
                    <select value={form.loja} onChange={e => set('loja', Number(e.target.value) || '')}
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                        style={inputStyle(!!erros.loja)}>
                        <option value="">Selecionar...</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>, erros.loja
                )}
                {campo('Fila', true,
                    <select value={form.origem} onChange={e => set('origem', e.target.value)}
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                        style={inputStyle(!!erros.origem)}>
                        <option value="recebimento">Recebimento (caminhão na porta)</option>
                        <option value="pre_lote">Pré-lote (antecipada)</option>
                    </select>, erros.origem
                )}
            </div>
            <div>
                <span className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>
                    CEASA <span className="font-normal">(opcional — compras também pode abrir cards)</span>
                </span>
                <div className="flex flex-wrap gap-2">
                    {[
                        { v: 3, l: 'CEASA', t: 'CEASA sem saber se é 1 ou 2' },
                        { v: 1, l: 'CEASA 1', t: '' },
                        { v: 2, l: 'CEASA 2', t: '' },
                    ].map(({ v, l, t }) => {
                        const ativo = form.ceasa === v;
                        return (
                            <button key={v} type="button" title={t || undefined} onClick={() => set('ceasa', ativo ? 0 : v)}
                                className="flex-1 sm:flex-none px-4 py-2.5 text-sm font-medium rounded-lg transition"
                                style={{
                                    background: ativo ? p.PURPLE + '22' : p.INPUT_BG,
                                    color: ativo ? p.PURPLE : p.MUTED,
                                    border: `1px solid ${ativo ? p.PURPLE : p.INPUT_BORDER}`,
                                }}>
                                {l}
                            </button>
                        );
                    })}
                </div>
            </div>
            {campo('Observação', false,
                <textarea value={form.observacao} onChange={e => set('observacao', e.target.value)}
                    rows={3} placeholder="Detalhes adicionais..."
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none resize-none"
                    style={inputStyle()} />
            )}
            {/* No celular o botão de confirmar vem primeiro e ocupa a linha
                inteira — é o que a pessoa quer tocar; "Cancelar" fica embaixo. */}
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-3 pt-3 mt-1" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                <button type="button" onClick={onCancelar} className="px-4 py-2.5 text-sm rounded-lg" style={{ color: p.MUTED }}>
                    Cancelar
                </button>
                <button type="submit" disabled={carregando}
                    className="px-5 py-2.5 text-sm font-medium text-white rounded-lg transition disabled:opacity-50"
                    style={{ background: p.ACCENT }}>
                    {carregando ? 'Salvando...' : labelSubmit}
                </button>
            </div>
        </form>
    );
}

// ─── Modal de cards (detalhe da nota) ───────────────────────────────────────────

/**
 * A resposta "não sobrou pendência" ao corrigir um cadastro (Card::SEM_TROCA).
 * Não é um tipo de card — é a ausência de um.
 */
const SEM_TROCA = 'nenhum' as const;

function ModalCards({ nota, onFechar, can, tiposCompras, tiposQualquerPapel, tiposRecebimento, substitutosCadastro, isDark, p }: {
    nota: Nota | null; onFechar: () => void; can: Permissoes;
    tiposCompras: TipoCard[];
    /** Card::abertosPorQualquerPapel() — quem não é pré-lote só enxerga estes */
    tiposQualquerPapel: TipoCard[];
    /** Card::abertosPeloRecebimento() — os de cima mais o Cadastro */
    tiposRecebimento: TipoCard[];
    /** Card::SUBSTITUTOS_DE_CADASTRO — por quais cards o cadastro pode ser trocado */
    substitutosCadastro: TipoCard[];
    isDark: boolean; p: Palette;
}) {
    // Compras só corrige os tipos dela (regra é do pré-lote); admin corrige tudo
    const meuPapel = usePage().props.auth.user.role;
    const ehCompras = meuPapel === 'compras';
    // Cards "de todo mundo" (Importar NF): recebimento e compras marcam via
    // "Corrigido"; o pré-lote usa "Resolver" (já tem o botão dele).
    const DE_TODOS: TipoCard[] = ['importar_nf'];
    // Recusa e Devolução: qualquer papel ABRE, mas fecha só quem está com a
    // mercadoria. Compras não aparece aqui de propósito — marcar "resolvido"
    // sem ver a doca seria afirmar que a carga saiu. (Card::TIPOS_DOCA)
    const DE_DOCA: TipoCard[] = ['recusa', 'devolucao'];
    const podeCorrigirEste = (c: Card) => {
        if (DE_TODOS.includes(c.tipo)) return !can.gerirCards && (meuPapel === 'recebimento' || meuPapel === 'compras');
        if (DE_DOCA.includes(c.tipo)) return !can.gerirCards && meuPapel === 'recebimento';
        return can.corrigirCard && (!ehCompras || tiposCompras.includes(c.tipo));
    };

    const [tipoNovo, setTipoNovo] = useState<TipoCard | ''>('');
    const [detalheNovo, setDetalheNovo] = useState('');
    const [erro, setErro] = useState<string | null>(null);
    const [ocupado, setOcupado] = useState(false);
    /** id do card de cadastro esperando a escolha da troca (null = ninguém) */
    const [trocando, setTrocando] = useState<number | null>(null);

    useEffect(() => { setTipoNovo(''); setDetalheNovo(''); setErro(null); setTrocando(null); }, [nota?.id]);

    if (!nota) return null;

    const liberada = nota.status === 'liberada';
    const ativos = nota.cards.filter(c => c.status !== 'resolvido');
    const podeLiberar = !liberada && ativos.length === 0;

    // Quais tipos este usuário pode ABRIR: pré-lote (e compras em CEASA) abrem
    // qualquer um; recebimento/compras (fora de CEASA) só o "Importar NF".
    // A lista de "qualquer papel abre" vem do backend (Card::abertosPorQualquerPapel).
    // Repetida aqui, ela já ficou para trás uma vez: Recusa e Devolução passaram
    // a ser aceitas pelo controller e seguiram fora do formulário de recebimento
    // e compras, que é o mesmo que não existirem para eles.
    const abreQualquer = can.gerirCards || (nota.ceasa > 0 && ehCompras);
    const deQualquerPapel = tiposQualquerPapel.length ? tiposQualquerPapel : [...DE_TODOS, ...DE_DOCA];

    /*
     * O recebimento enxerga um a mais: o card de Cadastro.
     *
     * A lista vem pronta do servidor (Card::abertosPeloRecebimento) em vez de
     * ser montada aqui com um `[...deQualquerPapel, 'cadastro']` — é a mesma
     * regra do comentário acima: lista repetida na tela é lista que fica para
     * trás quando o controller muda.
     *
     * `can.abrirCardCadastro` e não `meuPapel === 'recebimento'`: o pré-lote
     * também tem a permissão, mas ele já cai no `abreQualquer` acima.
     */
    const meusTipos = can.abrirCardCadastro && tiposRecebimento.length
        ? tiposRecebimento
        : deQualquerPapel;

    const tiposParaAbrir: TipoCard[] = abreQualquer
        ? opcoesTipos(nota)
        : ['recebimento', 'pre_lote', 'compras'].includes(meuPapel)
            ? opcoesTipos(nota).filter(t => meusTipos.includes(t))
            : [];

    const agir = (fn: () => void) => { setErro(null); setOcupado(true); fn(); };
    const opts = {
        onError: (e: Record<string, string>) => setErro(Object.values(e)[0] ?? 'Não foi possível concluir.'),
        onFinish: () => setOcupado(false),
        preserveScroll: true,
    };

    const abrirCard = (e: React.FormEvent) => {
        e.preventDefault();
        if (!tipoNovo) return;
        agir(() => router.post(route('notas.cards.store', nota.id), { tipo: tipoNovo, detalhe: detalheNovo || undefined } as any, {
            ...opts, onSuccess: () => { setTipoNovo(''); setDetalheNovo(''); },
        }));
    };

    /**
     * Corrigir. Cadastro leva junto a resposta de "o que ficou pendente":
     * o card que o substitui, ou SEM_TROCA quando não sobrou nada.
     *
     * Corrigir cadastro raramente fecha o assunto — em geral só muda de
     * assunto: o item passou a existir, mas existir não é estar no pedido.
     */
    const corrigir = (c: Card, substituto?: TipoCard | typeof SEM_TROCA) => agir(() => router.patch(
        route('notas.cards.corrigir', [nota.id, c.id]),
        (substituto ? { substituto } : {}) as any,
        { ...opts, onSuccess: () => setTrocando(null) },
    ));
    const resolver = (c: Card) => agir(() => router.patch(route('notas.cards.resolver', [nota.id, c.id]), {}, opts));
    const reabrir  = (c: Card) => agir(() => router.patch(route('notas.cards.reabrir', [nota.id, c.id]), {}, opts));
    const excluirCard = (c: Card) => {
        if (!confirm(`Excluir o card de ${TIPO_CARD_LABEL[c.tipo]}?`)) return;
        agir(() => router.delete(route('notas.cards.destroy', [nota.id, c.id]), opts));
    };
    const liberar = () => {
        if (!confirm(`Liberar a nota ${nota.numero_nota}?`)) return;
        agir(() => router.post(route('notas.liberar', nota.id), {}, { ...opts, onSuccess: () => onFechar() }));
    };

    const statusCor = nota.status === 'com_divergencia' ? p.RED
        : nota.status === 'reconferir' ? p.AMBER
        : nota.status === 'liberada' ? p.GREEN : p.MUTED;

    const btn = (label: string, cor: string, onClick: () => void) => (
        <button onClick={onClick} disabled={ocupado}
            className="px-2.5 py-1.5 text-xs font-medium rounded-md transition disabled:opacity-40"
            style={{ background: cor + '1a', color: cor, border: `1px solid ${cor}44` }}>
            {label}
        </button>
    );

    return (
        <Modal aberto={!!nota} onFechar={onFechar} titulo={`Nota ${nota.numero_nota} — ${nota.fornecedor.nome}`} p={p}>
            <div className="space-y-4">

                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                    <span className="font-medium px-2 py-0.5 rounded" style={{ background: statusCor + '1a', color: statusCor, border: `1px solid ${statusCor}44` }}>
                        {STATUS_NOTA_LABEL[nota.status]}
                    </span>
                    <span style={{ color: p.MUTED }}>{lojaNome(nota.loja)} · lançada por {nota.user.name.split(' ')[0]} em {nota.data_origem}</span>
                </div>

                {/* ── Cards ── */}
                <div className="space-y-2">
                    {nota.cards.length === 0 && (
                        <p className="text-sm py-2" style={{ color: p.MUTED }}>
                            Nenhuma divergência registrada{liberada ? '' : ' — nota aguardando análise do pré-lote'}.
                        </p>
                    )}
                    {nota.cards.map(c => (
                        /* A caixa do card empilha: a linha do card em cima e, quando
                           for o caso, o painel da troca embaixo.

                           O painel PRECISA ficar fora da linha. Enquanto ele era um
                           terceiro item dela, no desktop (sm:flex-row) entrava como
                           mais uma COLUNA — ao lado dos botões, com largura 100% —
                           e espremia o badge e o detalhe contra a borda. */
                        <div key={c.id} className="rounded-lg px-3 py-2"
                            style={{ border: `1px solid ${p.BORDER}`, background: p.SURFACE }}>

                            {/* No celular vira duas linhas: badge+detalhe em cima, os
                                botões embaixo — lado a lado eles espremiam o texto. */}
                            <div className="flex flex-col sm:flex-row sm:items-center gap-2">
                                <div className="flex items-center gap-2 min-w-0 flex-1">
                                    <CardBadge card={c} isDark={isDark} />
                                    <span className="text-xs truncate" style={{ color: p.MUTED }} title={c.detalhe ?? ''}>
                                        {c.detalhe || ''}
                                        {c.reaberturas > 0 && <em> · reaberto {c.reaberturas}x</em>}
                                    </span>
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5 shrink-0">
                                    {c.status === 'aberto' && podeCorrigirEste(c) && (
                                        c.tipo === 'cadastro'
                                            // Cadastro não fecha sozinho: pergunta o
                                            // que ficou pendente antes de corrigir.
                                            ? btn('Corrigido ✓', p.GREEN, () => setTrocando(c.id))
                                            : btn('Corrigido ✓', p.GREEN, () => corrigir(c))
                                    )}
                                    {c.status === 'aberto' && can.gerirCards && btn('Resolver', p.GREEN, () => resolver(c))}
                                    {c.status === 'aberto' && can.gerirCards && btn('Excluir', p.RED, () => excluirCard(c))}
                                    {c.status === 'resolvido' && can.gerirCards && btn('Reabrir', p.RED, () => reabrir(c))}
                                </div>
                            </div>

                            {/* ── O que ficou pendente depois do cadastro ──
                                Aparece na própria caixa do card, e não num modal por
                                cima deste: já estamos dentro de um. */}
                            {trocando === c.id && (
                                <div className="mt-2 pt-2" style={{ borderTop: `1px dashed ${p.BORDER}` }}>
                                    <p className="text-xs mb-2" style={{ color: p.TEXT }}>
                                        Item cadastrado. O que ficou pendente?
                                    </p>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {substitutosCadastro.map(t => btn(
                                            TIPO_CARD_LABEL[t] ?? t,
                                            p.ACCENT,
                                            () => corrigir(c, t),
                                        ))}
                                        {/* Nem todo cadastro deixa rastro: se o item já
                                            estava no pedido, forçar um card criaria uma
                                            divergência falsa. */}
                                        {btn('Nada — só conferir', p.GREEN, () => corrigir(c, SEM_TROCA))}
                                        <button onClick={() => setTrocando(null)} disabled={ocupado}
                                            className="px-2 py-1.5 text-xs rounded-md transition disabled:opacity-40"
                                            style={{ color: p.MUTED }}>
                                            cancelar
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {/* ── Abrir novo card (pré-lote; e compras quando a nota é de CEASA) ── */}
                {!liberada && tiposParaAbrir.length > 0 && (
                    <form onSubmit={abrirCard} className="flex flex-wrap items-center gap-2 pt-3" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                        <select value={tipoNovo} onChange={e => setTipoNovo(e.target.value as TipoCard)}
                            className="w-full sm:w-auto rounded-lg text-sm px-2.5 py-2 outline-none"
                            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}>
                            <option value="">Divergência...</option>
                            {tiposParaAbrir.map(t => <option key={t} value={t}>{TIPO_CARD_LABEL[t]}</option>)}
                        </select>
                        <input type="text" value={detalheNovo} onChange={e => setDetalheNovo(e.target.value)}
                            placeholder="Detalhe (opcional)" maxLength={500}
                            className="w-full sm:w-auto sm:flex-1 min-w-0 rounded-lg text-sm px-3 py-2 outline-none"
                            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
                        <button type="submit" disabled={!tipoNovo || ocupado}
                            className="w-full sm:w-auto px-3 py-2 text-sm font-medium text-white rounded-lg disabled:opacity-40"
                            style={{ background: p.ACCENT }}>
                            Abrir card
                        </button>
                    </form>
                )}

                {erro && <p className="text-xs" style={{ color: p.RED }}>{erro}</p>}

                {/* ── Liberar ── */}
                {can.liberarNota && !liberada && (
                    <div className="flex justify-stretch sm:justify-end pt-3" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                        <button onClick={liberar} disabled={!podeLiberar || ocupado}
                            title={podeLiberar ? 'Liberar a nota para o recebimento' : 'Resolva os cards em aberto antes de liberar'}
                            className="w-full sm:w-auto px-4 py-2.5 text-sm font-medium text-white rounded-lg transition disabled:opacity-40"
                            style={{ background: p.GREEN }}>
                            ✓ Liberar nota
                        </button>
                    </div>
                )}
            </div>
        </Modal>
    );
}

/** Tipos que ainda não têm card ativo nesta nota. */
// ─── Modal: editar nota JÁ LIBERADA (observação + lembrete CEASA) ──────────────

function ModalEditarLiberada({ nota, can, onFechar, p }: {
    nota: Nota | null; can: Permissoes; onFechar: () => void; p: Palette;
}) {
    const [obs, setObs] = useState('');
    const [ceasa, setCeasa] = useState(0);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);

    useEffect(() => {
        if (nota) { setObs(nota.observacao ?? ''); setCeasa(nota.ceasa); setErro(null); }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [nota?.id]);

    if (!nota) return null;

    // O lembrete CEASA só é editável por aqui depois de liberada; na fila o
    // recebimento/pré-lote já mexem nele pelo formulário completo.
    const podeCeasa = can.editarCeasaLiberada && nota.status === 'liberada';

    const salvar = () => {
        setSalvando(true);
        setErro(null);
        const dados: Record<string, string | number | null> = {};
        if (can.editarObservacao) dados.observacao = obs.trim() || null;
        if (podeCeasa) dados.ceasa = ceasa;
        router.patch(route('notas.editar-liberada', nota.id), dados as any, {
            preserveScroll: true,
            onSuccess: () => onFechar(),
            /*
             * Falhou: a janela FICA ABERTA, com o texto onde estava.
             *
             * Fechar aqui era o pior desfecho possível — a observação sumia da
             * tela sem ter sido gravada, e quem escreveu ia embora achando que
             * tinha salvado. Enquanto a janela está aberta o texto continua na
             * mão da pessoa, que pode tentar de novo sem redigitar.
             */
            onError: e => setErro(Object.values(e)[0] ?? 'Não foi possível salvar.'),
            // Cancelada no meio (outra navegação atropelou): idem, nada foi gravado.
            onCancel: () => setErro('O salvamento foi interrompido — tente de novo.'),
            onFinish: () => setSalvando(false),
        });
    };

    const CEASAS = [{ v: 0, l: 'Nenhum' }, { v: 3, l: 'CEASA' }, { v: 1, l: 'CEASA 1' }, { v: 2, l: 'CEASA 2' }];

    return (
        <Modal aberto={!!nota} onFechar={onFechar}
            titulo={`Nota ${nota.numero_nota}${nota.status === 'liberada' ? ' (liberada)' : ''}`} p={p}>
            <div className="space-y-4">
                {can.editarObservacao && (
                    <div>
                        <label className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>Observação</label>
                        <textarea value={obs} onChange={e => setObs(e.target.value)} rows={3} maxLength={500}
                            className="block w-full rounded-lg text-sm px-3 py-2 outline-none resize-none"
                            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
                    </div>
                )}
                {podeCeasa && (
                    <div>
                        <span className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>Lembrete CEASA</span>
                        <div className="flex flex-wrap gap-2">
                            {CEASAS.map(({ v, l }) => {
                                const ativo = ceasa === v;
                                return (
                                    <button key={v} type="button" onClick={() => setCeasa(v)}
                                        className="px-3 py-2 text-sm font-medium rounded-lg transition"
                                        style={{
                                            background: ativo ? p.PURPLE + '22' : p.INPUT_BG,
                                            color: ativo ? p.PURPLE : p.MUTED,
                                            border: `1px solid ${ativo ? p.PURPLE : p.INPUT_BORDER}`,
                                        }}>
                                        {l}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}
                {erro && (
                    <p className="text-sm rounded-lg px-3 py-2" role="alert"
                        style={{ background: p.RED + '1a', color: p.RED, border: `1px solid ${p.RED}44` }}>
                        {erro}
                    </p>
                )}
                <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-3 pt-3" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                    <button type="button" onClick={onFechar} className="px-4 py-2.5 text-sm rounded-lg" style={{ color: p.MUTED }}>
                        Cancelar
                    </button>
                    <button type="button" onClick={salvar} disabled={salvando}
                        className="px-5 py-2.5 text-sm font-medium text-white rounded-lg transition disabled:opacity-50"
                        style={{ background: p.ACCENT }}>
                        {salvando ? 'Salvando...' : 'Salvar'}
                    </button>
                </div>
            </div>
        </Modal>
    );
}

/**
 * Preferência de tela guardada no navegador, como o tema.
 *
 * Fica fora do banco de propósito: é gosto de quem está olhando, não dado da
 * operação — quem confere no celular quer a barra diferente de quem passa o
 * dia no desktop, e são a mesma conta.
 */
function usePreferencia(chave: string, inicial: boolean) {
    const [valor, setValor] = useState<boolean>(() => {
        if (typeof window === 'undefined') return inicial;
        const salvo = localStorage.getItem(chave);
        return salvo === null ? inicial : salvo === '1';
    });

    useEffect(() => {
        localStorage.setItem(chave, valor ? '1' : '0');
    }, [chave, valor]);

    return [valor, setValor] as const;
}

/**
 * Botão de recolher um grupo de chips.
 *
 * Recolhido continua mostrando o TOTAL: some o detalhe de qual tipo, nunca o
 * fato de que existe pendência. Uma seta pura esconderia as duas coisas.
 */
function BotaoGrupo({ aberto, onAlternar, rotulo, total, travado, p }: {
    aberto: boolean; onAlternar: () => void; rotulo: string; total: number;
    /** Filtro ativo neste grupo: recolher esconderia o filtro que está valendo. */
    travado: boolean; p: Palette;
}) {
    return (
        <button type="button" onClick={onAlternar} disabled={travado}
            title={travado
                ? 'Há filtro ativo aqui — remova para poder recolher'
                : aberto ? `Recolher ${rotulo.toLowerCase()}` : `Mostrar ${rotulo.toLowerCase()}`}
            className="flex items-center gap-1.5 text-sm px-2.5 py-1.5 rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed"
            style={{ color: p.MUTED, border: `1px solid ${p.BORDER}` }}
            onMouseEnter={e => !travado && (e.currentTarget.style.background = p.HOVER_ROW)}
            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
            <Icone path={aberto ? 'M19 9l-7 7-7-7' : 'M9 5l7 7-7 7'} className="w-3.5 h-3.5" />
            <span>{rotulo}</span>
            {!aberto && <strong style={{ color: p.TEXT }}>{total}</strong>}
        </button>
    );
}

function opcoesTipos(nota: Nota): TipoCard[] {
    const ativos = nota.cards.filter(c => c.status !== 'resolvido').map(c => c.tipo);
    const base: TipoCard[] = ['cadastro', 'regra', 'custo', 'quantidade', 'sem_pedido', 'item_n_pedido', 'importar_nf', 'recusa', 'devolucao'];
    // "Reconferir" só existe em nota de CEASA (pedido de nova conferência)
    if (nota.ceasa > 0) base.push('reconferir');
    return base.filter(t => !ativos.includes(t));
}

// ─── Ações da nota ──────────────────────────────────────────────────────────────

/** O que a linha da tabela e o cartão do celular precisam receber. */
interface AcoesProps {
    nota: Nota; onCards: (n: Nota) => void; onComentar: (n: Nota) => void;
    onAnexos: (n: Nota) => void;
    /** Encaminha a nota para o quadro de devoluções, já preenchida */
    onDevolucao: (n: Nota) => void;
    onEditar: (n: Nota) => void; onExcluir: (n: Nota) => void; onLiberar: (n: Nota) => void;
    onVisualizar: (n: Nota) => void; onCancelar: (n: Nota) => void;
    onObservacao: (n: Nota) => void; usuarioId: number;
    can: Permissoes; p: Palette;
}

/**
 * Os botões da nota — os mesmos na tabela do desktop e no cartão do celular.
 *
 * A classe `acoes-hover` (em app.css) é o pulo do gato: no desktop os botões
 * secundários continuam aparecendo só no hover, mas em aparelho sem mouse eles
 * nascem visíveis. Antes, com `opacity-0 group-hover:opacity-100`, no celular
 * eles ficavam invisíveis e a nota virava só leitura.
 */
function AcoesNota({ nota, onCards, onComentar, onAnexos, onDevolucao, onEditar, onExcluir, onLiberar, onVisualizar, onCancelar, onObservacao, usuarioId, can, p, alinhar }:
    AcoesProps & { alinhar: 'start' | 'end' }) {

    // Reserva (🙋‍♂️): se ninguém pegou, só aparece no hover; reservada, fica fixa.
    const olhando = nota.visualizando_por;
    const reservaMinha = olhando?.id === usuarioId;
    const reservaCor = reservaMinha ? p.GREEN : olhando ? p.AMBER : p.MUTED;
    const reservaTitulo = reservaMinha
        ? 'Você está olhando esta nota — clique para liberar'
        : olhando
            ? `${olhando.name.split(' ')[0]} está olhando esta nota`
            : 'Avisar que você está olhando esta nota';

    // Alvo de dedo no celular, discreto no desktop (onde a tabela é densa)
    const btn = 'p-2.5 lg:p-1.5 rounded-lg transition';

    return (
        <div className={`flex items-center gap-0.5 ${alinhar === 'end' ? 'justify-end' : ''}`}>
            {/* Reserva: clicável só p/ papéis operacionais; visitante só vê o indicador */}
            {can.interagir ? (
                <button onClick={() => onVisualizar(nota)} title={reservaTitulo}
                    className={`flex items-center ${btn} ${olhando ? '' : 'acoes-hover'}`}
                    style={{ background: olhando ? reservaCor + '22' : 'transparent' }}
                    onMouseEnter={e => !olhando && (e.currentTarget.style.background = p.HOVER_ROW)}
                    onMouseLeave={e => !olhando && (e.currentTarget.style.background = 'transparent')}>
                    {olhando
                        ? <Avatar user={olhando} size={22} ring={reservaCor} />
                        : <span style={{ color: p.MUTED }}>
                            <Icone path="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                          </span>}
                </button>
            ) : olhando ? (
                <span className="flex items-center p-1.5" title={reservaTitulo}>
                    <Avatar user={olhando} size={22} ring={reservaCor} />
                </span>
            ) : null}

            <button onClick={() => onComentar(nota)} title="Comentários"
                className={`flex items-center gap-1 ${btn} ${nota.comentarios_count > 0 ? '' : 'acoes-hover'}`}
                style={{ color: nota.comentarios_count > 0 ? p.ACCENT : p.MUTED }}
                onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                <Icone path="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.8 9.8 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                {nota.comentarios_count > 0 && <span className="text-xs font-medium">{nota.comentarios_count}</span>}
            </button>

            {/* Anexos: fica visível quando já tem arquivo (igual comentários),
                senão só no hover. Todo mundo vê — compras precisa da foto da
                avaria; quem ENVIA é recebimento e pré-lote (trava no servidor). */}
            <button onClick={() => onAnexos(nota)} title="Documentos e fotos"
                className={`flex items-center gap-1 ${btn} ${nota.anexos_count > 0 ? '' : 'acoes-hover'}`}
                style={{ color: nota.anexos_count > 0 ? p.PURPLE : p.MUTED }}
                onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                <Icone path={ICONE_ANEXO} />
                {nota.anexos_count > 0 && <span className="text-xs font-medium">{nota.anexos_count}</span>}
            </button>

            <div className="flex items-center gap-0.5 acoes-hover">
                <button onClick={() => onCards(nota)} title="Cards / divergências"
                    className={btn} style={{ color: p.AMBER }}
                    onMouseEnter={e => (e.currentTarget.style.background = p.AMBER + '1a')}
                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                    <Icone path="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </button>
                {/* Encaminhar para o quadro de devoluções: leva nota e
                    fornecedor prontos, e a pessoa completa o resto. */}
                {can.usarDevolucoes && (
                    <button onClick={() => onDevolucao(nota)} title="Encaminhar para devolução"
                        className={btn} style={{ color: p.ORANGE }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.ORANGE + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path={ICONE_PARA_DEVOLUCAO} />
                    </button>
                )}
                {can.liberarNota && nota.status === 'pendente' && (
                    <button onClick={() => onLiberar(nota)} title="Liberar nota"
                        className={btn} style={{ color: p.GREEN }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.GREEN + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M5 13l4 4L19 7" />
                    </button>
                )}
                {can.editarNotas ? (
                    <button onClick={() => onEditar(nota)} title="Editar"
                        className={btn} style={{ color: p.ACCENT }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.ACCENT + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </button>
                ) : can.editarObservacao ? (
                    /* Compras não edita a nota, mas registra o combinado na observação */
                    <button onClick={() => onObservacao(nota)} title="Editar observação"
                        className={btn} style={{ color: p.ACCENT }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.ACCENT + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </button>
                ) : null}
                {/* Fornecedor cancelou a NF: sai da fila e vai para "Canceladas" */}
                {can.cancelarNota && (
                    <button onClick={() => onCancelar(nota)} title="Nota cancelada pelo fornecedor"
                        className={btn} style={{ color: p.ORANGE }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.ORANGE + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </button>
                )}
                {can.gerenciarNotas && (
                    <button onClick={() => onExcluir(nota)} title="Excluir"
                        className={btn} style={{ color: p.RED }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </button>
                )}
            </div>
        </div>
    );
}

/** Selos que andam junto com o número da nota (CEASA, idade, fila anterior). */
function SelosNota({ nota, p }: { nota: Nota; p: Palette }) {
    const cor = nivelCor(nota.nivel, p);
    return (
        <>
            {nota.ceasa > 0 && (
                <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide"
                    style={{ background: p.PURPLE + '22', color: p.PURPLE, border: `1px solid ${p.PURPLE}44` }}
                    title="Nota de CEASA — compras pode abrir cards">
                    {nota.ceasa === 3 ? 'CEASA' : `CEASA ${nota.ceasa}`}
                </span>
            )}
            {nota.nivel !== 'normal' && (
                <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap"
                    style={{ background: cor + '22', color: cor, border: `1px solid ${cor}44` }}
                    title={nota.origem_anterior
                        ? `Nesta fila desde ${nota.origem_alterada_em ? new Date(nota.origem_alterada_em).toLocaleDateString('pt-BR') : '—'}`
                        : `Aberta desde ${nota.data_origem}`}>
                    {idadeTexto(nota.dias_aberta)}
                </span>
            )}
            {/* Trocou de fila: o relógio reiniciou aqui, mas ela esperou na fila anterior */}
            {nota.origem_anterior && (
                <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium whitespace-nowrap"
                    style={{ background: p.MUTED + '1a', color: p.MUTED, border: `1px solid ${p.MUTED}33` }}
                    title={`Esteve em ${ORIGEM_LABEL[nota.origem_anterior]} desde ${nota.origem_anterior_data} — a contagem de cores recomeçou ao mudar de fila`}>
                    {ORIGEM_LABEL[nota.origem_anterior]} desde {nota.origem_anterior_data}
                </span>
            )}
        </>
    );
}

// ─── Cartão da fila (celular) ───────────────────────────────────────────────────

/**
 * A mesma nota da tabela, empilhada. Abaixo de 1024px as 7 colunas só cabiam
 * rolando para o lado — e rolar uma fila inteira de lado, com o caminhão na
 * porta, não é trabalho: é obstáculo.
 */
function CartaoFila(props: AcoesProps & { isDark: boolean }) {
    const { nota, can, p, isDark, onCards } = props;
    const cor = nivelCor(nota.nivel, p);
    const ativos = nota.cards.filter(c => c.status !== 'resolvido');

    return (
        <div className="group px-4 py-3 space-y-2"
            style={{
                borderBottom: `1px solid ${p.BORDER}`,
                background: nota.nivel === 'normal' ? 'transparent' : cor + (nota.nivel === 'critico' ? '1f' : '12'),
            }}>

            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="font-semibold text-sm" style={{ color: p.TEXT }}>{nota.numero_nota}</span>
                <SelosNota nota={nota} p={p} />
            </div>

            <div className="flex items-center gap-1.5 text-sm min-w-0" style={{ color: p.TEXT }}>
                {nota.fornecedor.prioridade && (
                    <span title="Fornecedor prioritário" style={{ color: p.AMBER }}>★</span>
                )}
                <span className="truncate">{nota.fornecedor.nome}</span>
            </div>

            <button onClick={() => onCards(nota)} className="flex flex-wrap items-center gap-1 text-left"
                title="Abrir cards da nota">
                {ativos.length > 0
                    ? ativos.map(c => <CardBadge key={c.id} card={c} isDark={isDark} />)
                    : nota.status === 'reconferir'
                        ? <span className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium"
                            style={{ background: p.AMBER + '22', color: p.AMBER, border: `1px solid ${p.AMBER}44` }}>
                            Reconferir
                          </span>
                        : <span className="text-xs" style={{ color: p.MUTED }}>aguardando análise</span>}
            </button>

            {nota.observacao && (
                <p className="text-xs break-words" style={{ color: p.MUTED }}>{nota.observacao}</p>
            )}

            <div className="flex items-center justify-between gap-2 pt-1">
                <span className="text-xs truncate" style={{ color: p.MUTED }}>
                    {lojaNome(nota.loja)} · {nota.user.name.split(' ')[0]}
                </span>
                <AcoesNota {...props} can={can} alinhar="end" />
            </div>
        </div>
    );
}

/** Botão de ícone dos cartões de histórico (liberadas / canceladas). */
function BotaoIcone({ titulo, cor, path, onClick, sempre, children }: {
    titulo: string; cor: string; path: string; onClick: () => void;
    /** true = visível sempre; false = só no hover (desktop) */
    sempre?: boolean; children?: React.ReactNode;
}) {
    return (
        <button onClick={onClick} title={titulo}
            className={`inline-flex items-center gap-1 p-2.5 lg:p-1.5 rounded-lg transition ${sempre ? '' : 'acoes-hover'}`}
            style={{ color: cor }}
            onMouseEnter={e => (e.currentTarget.style.background = cor + '1a')}
            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
            <Icone path={path} />
            {children}
        </button>
    );
}

const ICONE_COMENTARIO = 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.8 9.8 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z';
/** Clipe de papel — documentos e fotos da nota */
const ICONE_ANEXO = 'M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13';
const ICONE_EDITAR = 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z';
const ICONE_VOLTAR = 'M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3';
/**
 * Encaminhar a nota para o quadro de devoluções.
 *
 * Nota fiscal com seta de retorno — e NÃO o ICONE_VOLTAR acima, que na mesma
 * linha já quer dizer "devolver ao recebimento". Dois atos diferentes com o
 * mesmo desenho seriam duas chances de clicar no errado.
 */
const ICONE_PARA_DEVOLUCAO = 'M16 15v-1a4 4 0 00-4-4H8m0 0l3 3m-3-3l3-3m9 14V5a2 2 0 00-2-2H6a2 2 0 00-2 2v16l4-2 4 2 4-2 4 2z';
const ICONE_LIXEIRA = 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16';

/** Nota liberada, no formato de cartão (celular). */
function CartaoLiberada({ nota, can, isDark, p, onCards, onComentar, onEditarObs, onDevolucao, onDevolver, onExcluir }: {
    nota: Nota; can: Permissoes; isDark: boolean; p: Palette;
    onCards: (n: Nota) => void; onComentar: (n: Nota) => void; onEditarObs: (n: Nota) => void;
    /** Encaminha para o quadro de devoluções, já preenchida */
    onDevolucao: (n: Nota) => void;
    onDevolver: (n: Nota) => void; onExcluir: (n: Nota) => void;
}) {
    return (
        <div className="group px-4 py-3 space-y-2 opacity-80" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="font-semibold text-sm line-through" style={{ color: p.TEXT }}>{nota.numero_nota}</span>
                {nota.ceasa > 0 && (
                    <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide"
                        style={{ background: p.PURPLE + '22', color: p.PURPLE, border: `1px solid ${p.PURPLE}44` }}
                        title="Nota de CEASA">
                        {nota.ceasa === 3 ? 'CEASA' : `CEASA ${nota.ceasa}`}
                    </span>
                )}
                {/* Liberada em outro dia, mas o caminhão trouxe hoje */}
                {nota.recebida_em?.slice(0, 10) === hoje() && nota.liberada_em?.slice(0, 10) !== hoje() && (
                    <span className="text-[11px] font-medium px-1.5 py-0.5 rounded"
                        style={{ background: p.GREEN + '22', color: p.GREEN }}
                        title={`Liberada no pré-lote em ${nota.liberada_em ? new Date(nota.liberada_em).toLocaleDateString('pt-BR') : '—'}`}>
                        recebida hoje
                    </span>
                )}
            </div>

            <p className="text-sm truncate" style={{ color: p.TEXT }}>{nota.fornecedor.nome}</p>

            <button onClick={() => onCards(nota)} className="flex flex-wrap items-center gap-1 text-left"
                title="Ver histórico de cards">
                {nota.cards.length === 0
                    ? <span className="text-xs" style={{ color: p.MUTED }}>sem divergência</span>
                    : nota.cards.map(c => <CardBadge key={c.id} card={c} isDark={isDark} />)}
            </button>

            {nota.observacao && <p className="text-xs break-words" style={{ color: p.MUTED }}>{nota.observacao}</p>}

            <div className="flex items-center justify-between gap-2 pt-1">
                <span className="text-xs truncate" style={{ color: p.MUTED }}>
                    {lojaNome(nota.loja)} · liberada por {nota.liberada_por?.name.split(' ')[0] ?? '—'}
                </span>
                <div className="flex items-center gap-0.5 shrink-0">
                    <BotaoIcone titulo="Comentários" cor={nota.comentarios_count > 0 ? p.ACCENT : p.MUTED}
                        path={ICONE_COMENTARIO} onClick={() => onComentar(nota)} sempre={nota.comentarios_count > 0}>
                        {nota.comentarios_count > 0 && <span className="text-xs font-medium">{nota.comentarios_count}</span>}
                    </BotaoIcone>
                    {(can.editarObservacao || can.editarCeasaLiberada) && (
                        <BotaoIcone titulo="Editar observação / CEASA" cor={p.ACCENT}
                            path={ICONE_EDITAR} onClick={() => onEditarObs(nota)} />
                    )}
                    {can.usarDevolucoes && (
                        <BotaoIcone titulo="Encaminhar para devolução" cor={p.ORANGE}
                            path={ICONE_PARA_DEVOLUCAO} onClick={() => onDevolucao(nota)} />
                    )}
                    {can.devolverNota && (
                        <BotaoIcone titulo="Devolver ao recebimento (conferido errado)" cor={p.AMBER}
                            path={ICONE_VOLTAR} onClick={() => onDevolver(nota)} />
                    )}
                    {can.excluirNotaLiberada && (
                        <BotaoIcone titulo="Excluir nota liberada" cor={p.RED}
                            path={ICONE_LIXEIRA} onClick={() => onExcluir(nota)} />
                    )}
                </div>
            </div>
        </div>
    );
}

/** Nota cancelada pelo fornecedor, no formato de cartão (celular). */
function CartaoCancelada({ nota, can, p, onComentar, onDescancelar }: {
    nota: Nota; can: Permissoes; p: Palette;
    onComentar: (n: Nota) => void; onDescancelar: (n: Nota) => void;
}) {
    return (
        <div className="group px-4 py-3 space-y-2 opacity-80" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="font-semibold text-sm line-through" style={{ color: p.TEXT }}>{nota.numero_nota}</span>
                {nota.ceasa > 0 && (
                    <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide"
                        style={{ background: p.PURPLE + '22', color: p.PURPLE, border: `1px solid ${p.PURPLE}44` }}
                        title="Nota de CEASA">
                        {nota.ceasa === 3 ? 'CEASA' : `CEASA ${nota.ceasa}`}
                    </span>
                )}
                <span className="text-xs" style={{ color: p.MUTED }}>{ORIGEM_LABEL[nota.origem]}</span>
            </div>

            <p className="text-sm truncate" style={{ color: p.TEXT }}>{nota.fornecedor.nome}</p>

            {nota.motivo_cancelamento && (
                <p className="text-xs break-words" style={{ color: p.TEXT }}>{nota.motivo_cancelamento}</p>
            )}

            <div className="flex items-center justify-between gap-2 pt-1">
                <span className="text-xs truncate" style={{ color: p.MUTED }}>
                    {lojaNome(nota.loja)} · cancelada por {nota.cancelada_por?.name.split(' ')[0] ?? '—'}
                </span>
                <div className="flex items-center gap-0.5 shrink-0">
                    <BotaoIcone titulo="Comentários" cor={nota.comentarios_count > 0 ? p.ACCENT : p.MUTED}
                        path={ICONE_COMENTARIO} onClick={() => onComentar(nota)} sempre={nota.comentarios_count > 0}>
                        {nota.comentarios_count > 0 && <span className="text-xs font-medium">{nota.comentarios_count}</span>}
                    </BotaoIcone>
                    {/* Cancelou por engano: volta para a fila */}
                    {can.cancelarNota && (
                        <BotaoIcone titulo="Desfazer cancelamento" cor={p.GREEN}
                            path={ICONE_VOLTAR} onClick={() => onDescancelar(nota)} />
                    )}
                </div>
            </div>
        </div>
    );
}

// ─── Linha da fila (tabela, a partir de 1024px) ──────────────────────────────────

function LinhaFila({ nota, onCards, onComentar, onAnexos, onDevolucao, onEditar, onExcluir, onLiberar, onVisualizar, onCancelar, onObservacao, usuarioId, can, isDark, p }:
    AcoesProps & { isDark: boolean }) {
    const cor = nivelCor(nota.nivel, p);
    const rowBg = nota.nivel === 'normal' ? 'transparent' : cor + (nota.nivel === 'critico' ? '1f' : '12');
    const ativos = nota.cards.filter(c => c.status !== 'resolvido');

    return (
        <tr className="group transition-colors"
            style={{ borderBottom: `1px solid ${p.BORDER}`, background: rowBg }}
            onMouseEnter={e => nota.nivel === 'normal' && (e.currentTarget.style.background = p.HOVER_ROW)}
            onMouseLeave={e => nota.nivel === 'normal' && (e.currentTarget.style.background = rowBg)}>
            <td className="px-4 py-3 text-sm">
                <div className="flex items-center gap-2">
                    <span className="font-medium" style={{ color: p.TEXT }}>{nota.numero_nota}</span>
                    <SelosNota nota={nota} p={p} />
                </div>
            </td>
            <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>
                {nota.fornecedor.prioridade && (
                    <span title="Fornecedor prioritário" className="mr-1" style={{ color: p.AMBER }}>★</span>
                )}
                {nota.fornecedor.nome}
            </td>
            <td className="px-4 py-3">
                <button onClick={() => onCards(nota)} className="flex flex-wrap items-center gap-1" title="Abrir cards da nota">
                    {ativos.length > 0
                        ? ativos.map(c => <CardBadge key={c.id} card={c} isDark={isDark} />)
                        : nota.status === 'reconferir'
                            ? <span className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                style={{ background: p.AMBER + '22', color: p.AMBER, border: `1px solid ${p.AMBER}44` }}>
                                Reconferir
                              </span>
                            : <span className="text-xs" style={{ color: p.MUTED }}>aguardando análise</span>}
                </button>
            </td>
            <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(nota.loja)}</td>
            <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }} title={nota.observacao ?? ''}>
                {nota.observacao || <span style={{ color: p.MUTED }}>—</span>}
            </td>
            <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{nota.user.name.split(' ')[0]}</td>
            <td className="px-4 py-3 text-right">
                <AcoesNota nota={nota} can={can} p={p} usuarioId={usuarioId} alinhar="end"
                    onCards={onCards} onComentar={onComentar} onAnexos={onAnexos} onDevolucao={onDevolucao} onEditar={onEditar}
                    onExcluir={onExcluir} onLiberar={onLiberar} onVisualizar={onVisualizar}
                    onCancelar={onCancelar} onObservacao={onObservacao} />
            </td>
        </tr>
    );
}

// ─── Página ─────────────────────────────────────────────────────────────────────

export default function Index({ recebimento, preLote, liberadas, canceladas, devolucoes, fornecedores, dataFiltro, resumoAlertas, resumoTipos, totalReconferir, filtros, opcoes }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const { can, user } = usePage().props.auth;

    const [modalNova, setModalNova] = useState(false);
    const [modalEditar, setModalEditar] = useState<Nota | null>(null);
    const [cardsId, setCardsId] = useState<number | null>(null);
    const [comentariosNota, setComentariosNota] = useState<Nota | null>(null);
    const [anexosNota, setAnexosNota] = useState<Nota | null>(null);
    const [editarLiberadaNota, setEditarLiberadaNota] = useState<Nota | null>(null);
    /* O quadro de devoluções tem estado próprio: ele muda por conta (conferir,
       lançar, excluir) sem passar pelo reload da fila de notas. */
    const [devolucoesL, setDevolucoesL] = useState(devolucoes);

    /**
     * Nota escolhida para virar devolução.
     *
     * A tela já sabe o número e o fornecedor — pedir que a pessoa redigite
     * abriria a porta para o erro que mais dói aqui: um card de devolução
     * apontando para a nota errada.
     */
    const [notaParaDevolucao, setNotaParaDevolucao] = useState<Nota | null>(null);

    const encaminharParaDevolucao = (n: Nota) => {
        setNotaParaDevolucao(n);
        // Leva a pessoa até o quadro: o formulário abre lá embaixo, e sem isso
        // ela clicaria no ícone e não veria nada acontecer.
        requestAnimationFrame(() =>
            document.getElementById('secao-devolucoes')?.scrollIntoView({ block: 'start' }));
    };
    const [echoTick, setEchoTick] = useState(0);
    const [erros, setErros] = useState<Record<string, string>>({});
    const [submetendo, setSubmetendo] = useState(false);
    const [buscaLocal, setBuscaLocal] = useState(filtros.busca ?? '');
    const [lojasSel, setLojasSel] = useState<number[]>(filtros.loja ?? []);

    // Listas em estado local — permitem atualizar só a linha que mudou (via evento),
    // em vez de todo cliente recarregar a fila inteira a cada mudança.
    const [recebimentoL, setRecebimentoL] = useState(recebimento);
    const [preLoteL, setPreLoteL] = useState(preLote);
    const [liberadasL, setLiberadasL] = useState(liberadas);
    const [canceladasL, setCanceladasL] = useState(canceladas);
    useEffect(() => setRecebimentoL(recebimento), [recebimento]);
    useEffect(() => setPreLoteL(preLote), [preLote]);
    useEffect(() => setLiberadasL(liberadas), [liberadas]);
    useEffect(() => setCanceladasL(canceladas), [canceladas]);
    useEffect(() => setDevolucoesL(devolucoes), [devolucoes]);

    /*
     * O quadro ao vivo, no canal próprio.
     *
     * Canal separado do 'notas' de propósito: o quadro não segue o filtro de
     * data nem os filtros da fila, então o reload da fila (que respeita tudo
     * isso) não serviria — e mandar o card por aqui evita recarregar a página
     * inteira quando alguém do outro setor confere algo.
     */
    useEffect(() => {
        if (!can.usarDevolucoes) return;

        window.Echo.private('devolucoes').listen('.DevolucaoAtualizada',
            (e: { devolucao?: Devolucao; removida?: number }) => {
                if (e.removida) {
                    setDevolucoesL(l => l.filter(d => d.id !== e.removida));
                    return;
                }
                if (!e.devolucao) return;

                setDevolucoesL(l => l.some(d => d.id === e.devolucao!.id)
                    ? l.map(d => d.id === e.devolucao!.id ? e.devolucao! : d)
                    : [e.devolucao!, ...l]);
            });

        return () => { window.Echo.leave('devolucoes'); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [can.usarDevolucoes]);

    // Filtro local da tabela de liberadas: caminhão na porta × pré-lote × ambos
    const [origemLiberadas, setOrigemLiberadas] = useState<OrigemNota | null>(null);
    const liberadasFiltradas = origemLiberadas
        ? liberadasL.filter(n => n.origem === origemLiberadas)
        : liberadasL;

    const isHoje = dataFiltro === hoje();
    // Visão "simples" (hoje, sem filtros): dá pra atualizar a linha no cliente com segurança
    const visaoSimples = isHoje && !filtros.busca && !filtros.loja?.length && !filtros.nivel && !filtros.status && !filtros.tipo;
    const visaoSimplesRef = useRef(visaoSimples);
    visaoSimplesRef.current = visaoSimples;

    /*
     * Reload de segurança (debounced) para os casos que não dá pra patchar no
     * cliente. Dois cuidados, os dois aprendidos na marra:
     *
     *  1. `async: true` — impede este reload de ATROPELAR o que a pessoa está
     *     fazendo. O Inertia atende uma visita SÍNCRONA por vez e aborta a que
     *     estiver em andamento quando outra começa. Este reload nasce de um
     *     evento do Reverb, ou seja da ação de QUALQUER pessoa e a qualquer
     *     instante — inclusive no meio do "Salvar" daqui. Era assim que uma
     *     observação recém-escrita sumia: o PATCH era cortado no caminho e
     *     nada avisava. Na fila assíncrona ele espera a vez sem cancelar
     *     ninguém, que é o certo para uma atualização de fundo.
     *
     *  2. Um de cada vez — a fila assíncrona aceita quantos vierem, e sem esta
     *     trava dois reloads poderiam voltar fora de ordem, deixando a tela com
     *     a fila mais VELHA das duas. Enquanto um está no ar, o evento que
     *     chega só marca que há novidade; o próximo sai quando o atual volta.
     */
    const reloadTimer = useRef<ReturnType<typeof setTimeout>>();
    const reloadNoAr  = useRef(false);
    const reloadPendente = useRef(false);

    const reloadDebounced = () => {
        if (reloadNoAr.current) { reloadPendente.current = true; return; }

        clearTimeout(reloadTimer.current);
        reloadTimer.current = setTimeout(() => {
            reloadNoAr.current = true;
            router.reload({
                // 'canceladas' entra junto: o caminho do patch (visaoSimples)
                // mexe nessa lista, e sem ela aqui a nota cancelada sumia da
                // fila mas não aparecia em "Canceladas neste dia" — sempre que
                // houvesse filtro ativo, que é quando este reload manda.
                only: ['recebimento', 'preLote', 'liberadas', 'canceladas', 'resumoAlertas', 'resumoTipos', 'totalReconferir'],
                async: true,
                onFinish: () => {
                    reloadNoAr.current = false;
                    if (reloadPendente.current) {
                        reloadPendente.current = false;
                        reloadDebounced();
                    }
                },
            });
        }, 400);
    };

    // Reposiciona a nota que mudou na lista certa (ou remove) mantendo a ordem
    const patch = (e: { nota?: Nota; removida?: number }) => {
        if (e.removida) {
            const id = e.removida;
            setRecebimentoL(l => l.filter(n => n.id !== id));
            setPreLoteL(l => l.filter(n => n.id !== id));
            setLiberadasL(l => l.filter(n => n.id !== id));
            setCanceladasL(l => l.filter(n => n.id !== id));
            return;
        }
        const nota = e.nota;
        if (!nota) return;

        // Cancelada sai de todas as filas e vai para a seção própria (do dia)
        const cancelada = nota.status === 'cancelada';
        const canceladaHoje = cancelada && (nota.cancelada_em ?? '').slice(0, 10) === hoje();
        setCanceladasL(l => {
            const sem = l.filter(n => n.id !== nota.id);
            return canceladaHoje
                ? [...sem, nota].sort((a, b) => (b.cancelada_em ?? '').localeCompare(a.cancelada_em ?? ''))
                : sem;
        });
        if (cancelada) {
            setRecebimentoL(l => l.filter(n => n.id !== nota.id));
            setPreLoteL(l => l.filter(n => n.id !== nota.id));
            setLiberadasL(l => l.filter(n => n.id !== nota.id));
            return;
        }

        const naFila = nota.status !== 'liberada';
        // Liberadas mostra as do dia; evita puxar p/ hoje uma liberada em dia passado
        // — salvo se o caminhão a trouxe hoje (recebida_em), aí ela entra na lista.
        const liberadaHoje = !naFila && (
            (nota.liberada_em ?? '').slice(0, 10) === hoje() ||
            (nota.recebida_em ?? '').slice(0, 10) === hoje()
        );
        const sem = (l: Nota[]) => l.filter(n => n.id !== nota.id);
        const asc = (l: Nota[]) => [...l].sort((a, b) => a.created_at.localeCompare(b.created_at));
        // Pré-lote: fornecedor prioritário no topo; dentro do grupo, por data.
        const prio = (n: Nota) => (n.fornecedor.prioridade ? 0 : 1);
        const ascPrio = (l: Nota[]) => [...l].sort((a, b) => prio(a) - prio(b) || a.created_at.localeCompare(b.created_at));
        const desc = (l: Nota[]) => [...l].sort((a, b) => (b.liberada_em ?? '').localeCompare(a.liberada_em ?? ''));
        setRecebimentoL(l => naFila && nota.origem === 'recebimento' ? asc([...sem(l), nota]) : sem(l));
        setPreLoteL(l => naFila && nota.origem === 'pre_lote' ? ascPrio([...sem(l), nota]) : sem(l));
        setLiberadasL(l => liberadaHoje ? desc([...sem(l), nota]) : sem(l));
    };

    useEffect(() => {
        window.Echo.private('notas').listen('.NotaAtualizada', (e: { nota?: Nota; removida?: number }) => {
            setEchoTick(t => t + 1); // recarrega a thread aberta de comentários
            if (visaoSimplesRef.current && (e?.nota || e?.removida)) {
                patch(e);            // atualiza só a linha
            } else {
                reloadDebounced();   // casos estruturais/filtrados: reload leve
            }
        });
        return () => {
            window.Echo.leave('notas');
            clearTimeout(reloadTimer.current);
            // Corta a corrente: sem isto, o onFinish de um reload que voltasse
            // depois de a tela sair agendaria o próximo no vazio.
            reloadPendente.current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const todas = [...recebimentoL, ...preLoteL, ...liberadasL];
    // O modal de cards deriva das listas locais — reflete o realtime na hora
    const notaCards = cardsId ? todas.find(n => n.id === cardsId) ?? null : null;

    // Contadores: na visão simples derivam das listas locais (refletem os patches);
    // com filtros ativos, vêm do servidor (as listas estão filtradas)
    const filaLocal = [...recebimentoL, ...preLoteL];
    const resumoEfetivo = visaoSimples ? {
        critico: filaLocal.filter(n => n.nivel === 'critico').length,
        alerta:  filaLocal.filter(n => n.nivel === 'alerta').length,
        atencao: filaLocal.filter(n => n.nivel === 'atencao').length,
    } : resumoAlertas;
    const totalReconferirEfetivo = visaoSimples
        ? filaLocal.filter(n => n.status === 'reconferir').length
        : totalReconferir;

    // Idem para os tipos de divergência: card resolvido não conta, só o que pede ação
    const temCardAtivo = (n: Nota, tipo: TipoCard) =>
        n.cards.some(c => c.tipo === tipo && c.status !== 'resolvido');

    const resumoTiposEfetivo: ResumoTipos = visaoSimples
        ? (opcoes.tipos.reduce((acc, t) => {
            acc[t] = filaLocal.filter(n => temCardAtivo(n, t)).length;
            return acc;
        }, {} as ResumoTipos))
        : resumoTipos;

    const paramsAtuais = () => ({
        data: dataFiltro,
        busca: buscaLocal || undefined,
        loja: lojasSel.length ? lojasSel : undefined,
        nivel: filtros.nivel || undefined,
        status: filtros.status || undefined,
        tipo: filtros.tipo || undefined,
    });

    const irPara = (extras: Record<string, unknown> = {}) =>
        router.get(route('notas.index'), { ...paramsAtuais(), ...extras }, { preserveState: true, replace: true });

    const mudarData = (d: string) => irPara({ data: d });
    const filtrarNivel = (n: Nivel | null) => irPara({ nivel: n ?? undefined });
    const filtrarStatus = (s: string | null) => irPara({ status: s ?? undefined });
    const filtrarTipo = (t: TipoCard | null) => irPara({ tipo: t ?? undefined });
    const alternarLoja = (l: number) => {
        const novo = lojasSel.includes(l) ? lojasSel.filter(x => x !== l) : [...lojasSel, l];
        setLojasSel(novo);
        irPara({ loja: novo.length ? novo : undefined });
    };
    const diaAnterior = () => mudarData(format(subDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const diaSeguinte = () => mudarData(format(addDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const aplicarFiltros = () => irPara();
    const limparFiltros = () => {
        setBuscaLocal(''); setLojasSel([]);
        router.get(route('notas.index'), { data: dataFiltro }, { preserveState: true, replace: true });
    };
    const filtrosAtivos = !!(filtros.busca || filtros.loja?.length || filtros.nivel || filtros.status || filtros.tipo);

    const criar = (dados: any, confirmarMover = false) => {
        setSubmetendo(true);
        router.post(route('notas.store'), { ...dados, confirmar_mover: confirmarMover }, {
            preserveScroll: true,
            onSuccess: () => { setModalNova(false); setErros({}); },
            onError: e => {
                // A nota já existe na outra fila: o backend devolve a fila atual
                // em "duplicada" e espera a confirmação para mover.
                if (e.duplicada) {
                    setErros({});
                    const atual = ORIGEM_LABEL[e.duplicada] ?? e.duplicada;
                    const nova = ORIGEM_LABEL[dados.origem] ?? dados.origem;
                    if (confirm(`Esta nota já está em "${atual}". Deseja mover para "${nova}"?`)) {
                        criar(dados, true);
                    }
                    return;
                }
                setErros(e);
            },
            onFinish: () => setSubmetendo(false),
        });
    };

    const salvarEdicao = (dados: any) => {
        if (!modalEditar) return;
        setSubmetendo(true);
        router.patch(route('notas.update', modalEditar.id), dados, {
            onSuccess: () => { setModalEditar(null); setErros({}); },
            onError: e => setErros(e),
            onFinish: () => setSubmetendo(false),
        });
    };

    const liberarRapido = (n: Nota) => {
        if (!confirm(`Liberar a nota ${n.numero_nota} (${n.fornecedor.nome})?`)) return;
        router.post(route('notas.liberar', n.id), {}, { preserveScroll: true });
    };

    // 🙋‍♂️ "estou olhando esta nota". O servidor decide: reserva, solta (se já é
    // minha) ou avisa quem está nela (volta em flash.erro, que vira toast).
    const visualizar = (n: Nota) => {
        router.post(route('notas.visualizar', n.id), {}, { preserveScroll: true, preserveState: true });
    };

    // Estorna a liberação: tira das liberadas e volta a nota para o recebimento
    // Fornecedor cancelou a NF: sai da fila e vai para "Canceladas neste dia"
    const cancelar = (n: Nota) => {
        const motivo = prompt(`Cancelar a nota ${n.numero_nota} (${n.fornecedor.nome})?\n\nMotivo (opcional):`);
        if (motivo === null) return; // desistiu
        router.post(route('notas.cancelar', n.id), { motivo: motivo || undefined } as any, { preserveScroll: true });
    };

    const descancelar = (n: Nota) => {
        if (!confirm(`Desfazer o cancelamento da nota ${n.numero_nota}? Ela volta para a fila.`)) return;
        router.post(route('notas.descancelar', n.id), {}, { preserveScroll: true });
    };

    const devolver = (n: Nota) => {
        if (!confirm(`Devolver a nota ${n.numero_nota} ao recebimento? Ela sai das liberadas e volta para a fila para reajuste.`)) return;
        router.post(route('notas.devolver', n.id), {}, { preserveScroll: true });
    };

    const excluir = (n: Nota) => {
        // A nota liberada já foi concluída: vale um aviso mais explícito
        const aviso = n.status === 'liberada'
            ? `Excluir a nota ${n.numero_nota}, que JÁ FOI LIBERADA? Ela sai do histórico do dia. Esta ação pode ser revertida pelo administrador.`
            : `Excluir a nota ${n.numero_nota}? Esta ação pode ser revertida pelo administrador.`;

        if (!confirm(aviso)) return;
        router.delete(route('notas.destroy', n.id));
    };

    const sla = opcoes.sla ?? { atencao: 1, alerta: 3, critico: 7 };
    const faixaTexto: Record<Exclude<Nivel, 'normal'>, string> = {
        critico: `${sla.critico}+ dias`,
        alerta: `${sla.alerta}–${sla.critico - 1} dias`,
        atencao: `${sla.atencao}–${sla.alerta - 1} dias`,
    };
    const temAlertas = resumoEfetivo.critico + resumoEfetivo.alerta + resumoEfetivo.atencao > 0;
    const tiposComPendencia = opcoes.tipos.filter(t => (resumoTiposEfetivo[t] ?? 0) > 0);
    const temFiltros = temAlertas || totalReconferirEfetivo > 0 || tiposComPendencia.length > 0;
    const filtrandoReconferir = filtros.status === 'reconferir';

    // ── Recolher os dois grupos de chips ──────────────────────────────────────
    //
    // PRAZOS (críticas/alerta/atenção) são a idade da nota. Nota não envelhece
    // na doca: ela envelhece esperando resposta do fornecedor ou correção no
    // ERP — coisa que a equipe já sabe e não consegue destravar olhando a fila.
    // Por isso são o grupo que mais compensa fechar, e não o contrário.
    //
    // DIVERGÊNCIAS é onde está o trabalho que dá para fazer hoje. "Reconferir"
    // (nota com tudo corrigido, esperando o pré-lote liberar) mora aqui, e não
    // com os prazos: é fase do card, não idade.
    const [verPrazos, setVerPrazos] = usePreferencia('nfs:filtros:prazos', true);
    const [verDivergencias, setVerDivergencias] = usePreferencia('nfs:filtros:divergencias', true);

    // Grupo com filtro ativo não recolhe: sumir com o chip que está filtrando a
    // tela deixaria a pessoa vendo uma fila cortada sem nada explicando por quê.
    const prazosTravados = !!filtros.nivel;
    const divergenciasTravadas = !!filtros.tipo || filtrandoReconferir;
    const prazosAbertos = verPrazos || prazosTravados;
    const divergenciasAbertas = verDivergencias || divergenciasTravadas;

    const totalPrazos = resumoEfetivo.critico + resumoEfetivo.alerta + resumoEfetivo.atencao;
    const totalDivergencias = totalReconferirEfetivo
        + tiposComPendencia.reduce((soma, t) => soma + (resumoTiposEfetivo[t] ?? 0), 0);

    const COLS_FILA = ['Nota', 'Fornecedor', 'Divergências', 'Loja', 'Observação', 'Lançado', ''];
    const COLS_LIBERADAS = ['Nota', 'Fornecedor', 'Divergências', 'Loja', 'Observação', 'Liberada por', ''];
    const COLS_CANCELADAS = ['Nota', 'Fornecedor', 'Loja', 'Fila', 'Motivo', 'Cancelada por', ''];

    const inputCtrl = { background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` };

    const secaoFila = (id: string, titulo: string, subtitulo: string, notas: Nota[], corBadge: string) => (
        /* `scroll-mt-20`: a navbar é fixa, e sem a margem o atalho deixaria o
           título da seção escondido atrás dela. */
        <div id={id} className="rounded-xl overflow-hidden scroll-mt-20" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            <div className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.TEXT }}>
                    {titulo}
                    <span className="text-xs font-normal" style={{ color: p.MUTED }}>{subtitulo}</span>
                    <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                        style={{ background: corBadge + '22', color: corBadge, border: `1px solid ${corBadge}33` }}>
                        {notas.length}
                    </span>
                </h2>
            </div>
            {/* Tabela no desktop, cartões empilhados no celular — mesmos dados,
                mesmas ações, sem rolagem lateral. */}
            <div className="hidden lg:block rolagem-x">
                <table className="min-w-full">
                    <THead colunas={COLS_FILA} p={p} />
                    <tbody>
                        {notas.length === 0 ? (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                Nenhuma nota nesta fila.
                            </td></tr>
                        ) : notas.map(n => (
                            <LinhaFila key={n.id} nota={n} can={can} isDark={isDark} p={p}
                                onCards={x => setCardsId(x.id)} onComentar={setComentariosNota}
                                onAnexos={setAnexosNota} onDevolucao={encaminharParaDevolucao}
                                onEditar={setModalEditar} onExcluir={excluir} onLiberar={liberarRapido}
                                onVisualizar={visualizar} onCancelar={cancelar}
                                onObservacao={setEditarLiberadaNota} usuarioId={user.id} />
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="lg:hidden">
                {notas.length === 0 ? (
                    <p className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                        Nenhuma nota nesta fila.
                    </p>
                ) : notas.map(n => (
                    <CartaoFila key={n.id} nota={n} can={can} isDark={isDark} p={p}
                        onCards={x => setCardsId(x.id)} onComentar={setComentariosNota}
                        onAnexos={setAnexosNota} onDevolucao={encaminharParaDevolucao}
                        onEditar={setModalEditar} onExcluir={excluir} onLiberar={liberarRapido}
                        onVisualizar={visualizar} onCancelar={cancelar}
                        onObservacao={setEditarLiberadaNota} usuarioId={user.id} />
                ))}
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Notas" />

            <Modal aberto={modalNova} onFechar={() => setModalNova(false)} titulo="Lançar nota" p={p}>
                <FormNota fornecedores={fornecedores} opcoes={opcoes} onSubmit={criar}
                    origemDefault={user.role === 'pre_lote' ? 'pre_lote' : 'recebimento'}
                    onCancelar={() => setModalNova(false)} carregando={submetendo} erros={erros}
                    labelSubmit="Lançar nota" p={p} />
            </Modal>

            <Modal aberto={!!modalEditar} onFechar={() => setModalEditar(null)} titulo="Editar nota" p={p}>
                {modalEditar && (
                    <FormNota fornecedores={fornecedores} opcoes={opcoes} inicial={modalEditar}
                        origemDefault={modalEditar.origem}
                        onSubmit={salvarEdicao} onCancelar={() => setModalEditar(null)}
                        carregando={submetendo} erros={erros} labelSubmit="Salvar alterações" p={p} />
                )}
            </Modal>

            <ModalCards nota={notaCards} onFechar={() => setCardsId(null)} can={can}
                tiposCompras={opcoes.tiposCompras ?? ['cadastro', 'custo', 'quantidade', 'sem_pedido', 'item_n_pedido']}
                tiposQualquerPapel={opcoes.tiposQualquerPapel ?? []}
                tiposRecebimento={opcoes.tiposRecebimento ?? []}
                substitutosCadastro={opcoes.substitutosCadastro ?? []} isDark={isDark} p={p} />

            <ModalComentarios
                aberto={!!comentariosNota}
                onFechar={() => setComentariosNota(null)}
                baseUrl={comentariosNota ? `/notas/${comentariosNota.id}/comentarios` : null}
                titulo={comentariosNota ? `Nota ${comentariosNota.numero_nota} — ${comentariosNota.fornecedor.nome}` : ''}
                onMudou={() => router.reload({ only: ['recebimento', 'preLote', 'liberadas'] })}
                recarregarToken={echoTick}
                podeComentar={can.interagir}
                p={p} />

            <ModalAnexos
                aberto={!!anexosNota}
                onFechar={() => setAnexosNota(null)}
                baseUrl={anexosNota ? `/notas/${anexosNota.id}/anexos` : null}
                titulo={anexosNota ? `Nota ${anexosNota.numero_nota} — ${anexosNota.fornecedor.nome}` : ''}
                onMudou={() => router.reload({ only: ['recebimento', 'preLote', 'liberadas'] })}
                podeAnexar={can.anexarNota}
                p={p} />

            <ModalEditarLiberada nota={editarLiberadaNota} can={can}
                onFechar={() => setEditarLiberadaNota(null)} p={p} />

            <div className="flex-1 w-full py-6 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-4 transition-colors duration-200"
                style={{ background: p.BG }}>

                {/* ── Barra de controles ───────────────────────────────────────
                    No desktop é uma faixa só. No celular vira três: dia +
                    lançar nota, busca, e as lojas (que rolam para o lado — são
                    nove botões e não cabem numa tela de 375px). */}
                <div className="space-y-2.5">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <div className="flex items-center gap-1 rounded-lg px-2 py-1.5"
                            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                            <button onClick={diaAnterior} className="p-1.5 rounded transition" style={{ color: p.MUTED }} title="Dia anterior">
                                <Icone path="M15 19l-7-7 7-7" />
                            </button>
                            <input type="date" value={dataFiltro} onChange={e => mudarData(e.target.value)}
                                className="border-none text-sm font-medium focus:ring-0 p-0 bg-transparent cursor-pointer min-w-0"
                                style={{ color: p.TEXT, colorScheme: isDark ? 'dark' : 'light' }} />
                            <button onClick={diaSeguinte} disabled={isHoje}
                                className="p-1.5 rounded transition disabled:opacity-30" style={{ color: p.MUTED }} title="Próximo dia">
                                <Icone path="M9 5l7 7-7 7" />
                            </button>
                        </div>

                        {isHoje && (
                            <span className="text-xs font-medium px-2.5 py-1 rounded-md"
                                style={{ background: p.ACCENT + '22', color: p.ACCENT, border: `1px solid ${p.ACCENT}44` }}>
                                Hoje
                            </span>
                        )}

                        {can.lancarNota && (
                            <button onClick={() => setModalNova(true)}
                                className="w-full sm:w-auto sm:ml-auto flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-medium text-white rounded-lg transition"
                                style={{ background: p.ACCENT }}
                                onMouseEnter={e => (e.currentTarget.style.filter = 'brightness(1.1)')}
                                onMouseLeave={e => (e.currentTarget.style.filter = 'none')}>
                                <Icone path="M12 4v16m8-8H4" /> Lançar nota
                            </button>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        <div className="relative w-full sm:w-56">
                            <input type="search" placeholder="Buscar nota ou fornecedor..."
                                value={buscaLocal} onChange={e => setBuscaLocal(e.target.value)}
                                onKeyDown={e => e.key === 'Enter' && aplicarFiltros()}
                                className="w-full rounded-lg text-sm pl-8 pr-3 py-2 outline-none" style={inputCtrl} />
                            <span className="absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: p.MUTED }}>
                                <Icone path="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </span>
                        </div>

                        <div className="flex items-center gap-1.5 max-w-full rolagem-x scrollbar-oculta -my-1 py-1">
                            <span className="text-sm shrink-0" style={{ color: p.MUTED }}>Lojas:</span>
                            {opcoes.lojas.map(l => {
                                const ativo = lojasSel.includes(l);
                                return (
                                    <button key={l} onClick={() => alternarLoja(l)}
                                        title={ativo ? `Desmarcar loja ${String(l).padStart(2, '0')}` : `Mostrar só as lojas marcadas`}
                                        className="text-sm px-2.5 py-2 rounded-lg transition font-medium shrink-0"
                                        style={{
                                            background: ativo ? p.ACCENT + '22' : 'transparent',
                                            border: `1px solid ${ativo ? p.ACCENT : p.BORDER}`,
                                            color: ativo ? p.ACCENT : p.TEXT,
                                        }}>
                                        {String(l).padStart(2, '0')}
                                    </button>
                                );
                            })}
                        </div>

                        <button onClick={aplicarFiltros}
                            className="px-3.5 py-2 text-sm font-medium rounded-lg transition shrink-0"
                            style={{ background: p.SURFACE, color: p.TEXT, border: `1px solid ${p.BORDER}` }}>
                            Filtrar
                        </button>

                        {filtrosAtivos && (
                            <button onClick={limparFiltros} className="text-xs flex items-center gap-1 px-1 py-2" style={{ color: p.MUTED }}>
                                <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" /> Limpar
                            </button>
                        )}
                    </div>
                </div>

                {/* ── Chips de filtro: prazos | divergências ──────────────────── */}
                {temFiltros && (
                    <div className="flex flex-wrap items-center gap-2">
                        {temAlertas && (
                            <BotaoGrupo aberto={prazosAbertos} onAlternar={() => setVerPrazos(v => !v)}
                                rotulo="Prazos" total={totalPrazos} travado={prazosTravados} p={p} />
                        )}

                        {prazosAbertos && (['critico', 'alerta', 'atencao'] as const).map(n => {
                            const qtd = resumoEfetivo[n];
                            if (!qtd) return null;
                            const cor = nivelCor(n, p);
                            const ativo = filtros.nivel === n;
                            return (
                                <button key={n} onClick={() => filtrarNivel(ativo ? null : n)}
                                    title={ativo ? 'Remover filtro' : `Ver só as ${NIVEL_LABEL[n]}`}
                                    className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg transition"
                                    style={{
                                        background: ativo ? cor + '33' : cor + '14',
                                        border: `1px solid ${cor}${ativo ? 'aa' : '44'}`,
                                        color: cor,
                                    }}>
                                    <strong>{qtd}</strong>
                                    <span>{NIVEL_LABEL[n]}</span>
                                    <span className="text-xs" style={{ opacity: 0.75 }}>({faixaTexto[n]})</span>
                                </button>
                            );
                        })}

                        {/* Divisória só quando há algo dos dois lados dela */}
                        {temAlertas && (totalReconferirEfetivo > 0 || tiposComPendencia.length > 0) && (
                            <span className="w-px h-5 mx-0.5" style={{ background: p.BORDER }} />
                        )}

                        {(totalReconferirEfetivo > 0 || tiposComPendencia.length > 0) && (
                            <BotaoGrupo aberto={divergenciasAbertas} onAlternar={() => setVerDivergencias(v => !v)}
                                rotulo="Divergências" total={totalDivergencias} travado={divergenciasTravadas} p={p} />
                        )}

                        {/* Reconferir: tudo corrigido, esperando o pré-lote conferir e liberar.
                            Fica com as divergências porque é fase do card, não idade da nota. */}
                        {divergenciasAbertas && totalReconferirEfetivo > 0 && (
                            <button onClick={() => filtrarStatus(filtrandoReconferir ? null : 'reconferir')}
                                title={filtrandoReconferir ? 'Remover filtro' : 'Ver só as prontas p/ liberar'}
                                className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg transition"
                                style={{
                                    background: filtrandoReconferir ? p.AMBER + '33' : p.AMBER + '14',
                                    border: `1px solid ${p.AMBER}${filtrandoReconferir ? 'aa' : '44'}`,
                                    color: p.AMBER,
                                }}>
                                <strong>{totalReconferirEfetivo}</strong>
                                <span>reconferir</span>
                                <span className="text-xs" style={{ opacity: 0.75 }}>(pronta p/ liberar)</span>
                            </button>
                        )}

                        {/* Divergências em aberto: "quais notas estão travadas no custo?" */}
                        {divergenciasAbertas && tiposComPendencia.map(t => {
                            const cor = (isDark ? CARD_COR_DARK : CARD_COR_LIGHT)[t];
                            const ativo = filtros.tipo === t;
                            return (
                                <button key={t} onClick={() => filtrarTipo(ativo ? null : t)}
                                    title={ativo ? 'Remover filtro' : `Ver só as notas com divergência de ${(TIPO_CARD_LABEL[t] ?? t).toLowerCase()} em aberto`}
                                    className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg transition"
                                    style={{
                                        background: ativo ? cor.bg : 'transparent',
                                        border: `1px solid ${cor.border}`,
                                        color: cor.text,
                                        opacity: ativo ? 1 : 0.85,
                                    }}>
                                    <strong>{resumoTiposEfetivo[t]}</strong>
                                    <span>{TIPO_CARD_LABEL[t] ?? t}</span>
                                </button>
                            );
                        })}

                        {(filtros.nivel || filtros.status || filtros.tipo) && (
                            <button onClick={() => irPara({ nivel: undefined, status: undefined, tipo: undefined })}
                                className="text-xs flex items-center gap-1" style={{ color: p.MUTED }}>
                                <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" /> Ver todas
                            </button>
                        )}
                    </div>
                )}

                {/* ── Filas ───────────────────────────────────────────────────── */}
                {secaoFila('secao-recebimento', 'Recebimento', 'caminhão na porta — prioridade', recebimentoL, p.RED)}

                {/* ── Devoluções ────────────────────────────────────────
                    Logo abaixo do recebimento, e não depois das duas filas: a
                    devolução nasce na doca, com o caminhão ainda na porta, e
                    é ali que ela precisa estar à vista. Embaixo do pré-lote
                    ela ficava atrás de uma lista que costuma ser longa. */}
                {can.usarDevolucoes && (
                    <div id="secao-devolucoes" className="scroll-mt-20">
                        <QuadroDevolucoes
                            devolucoes={devolucoesL}
                            podeUsar={can.usarDevolucoes}
                            meuNome={user.name}
                            onMudou={setDevolucoesL}
                            daNota={notaParaDevolucao
                                ? { numero_nota: notaParaDevolucao.numero_nota, fornecedor: notaParaDevolucao.fornecedor.nome }
                                : null}
                            onFecharDaNota={() => setNotaParaDevolucao(null)}
                            p={p}
                        />
                    </div>
                )}

                {secaoFila('secao-pre-lote', 'Pré-lote', 'notas antecipadas', preLoteL, p.ACCENT)}

                {/* ── Liberadas ───────────────────────────────────────────────── */}
                <div id="secao-liberadas" className="rounded-xl overflow-hidden scroll-mt-20" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="flex items-center justify-between px-5 py-3.5 gap-3 flex-wrap" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                        <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.MUTED }}>
                            Liberadas neste dia
                            <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                                style={{ background: p.GREEN + '22', color: p.GREEN, border: `1px solid ${p.GREEN}33` }}>
                                {liberadasFiltradas.length}
                            </span>
                        </h2>
                        {/* Filtro por fila de origem: caminhão na porta × pré-lote × ambos */}
                        <div className="flex items-center gap-1.5">
                            {([null, 'recebimento', 'pre_lote'] as const).map(o => {
                                const ativo = origemLiberadas === o;
                                const rotulo = o === null ? 'Ambos' : ORIGEM_LABEL[o];
                                const cor = o === 'recebimento' ? p.RED : o === 'pre_lote' ? p.ACCENT : p.MUTED;
                                return (
                                    <button key={rotulo} onClick={() => setOrigemLiberadas(o)}
                                        className="text-xs font-medium px-2.5 py-1 rounded-lg transition"
                                        style={{
                                            background: ativo ? cor + '22' : 'transparent',
                                            color: ativo ? cor : p.MUTED,
                                            border: `1px solid ${ativo ? cor : p.BORDER}`,
                                        }}>
                                        {rotulo}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div className="hidden lg:block rolagem-x">
                        <table className="min-w-full">
                            <THead colunas={COLS_LIBERADAS} p={p} />
                            <tbody>
                                {liberadasFiltradas.length === 0 ? (
                                    <tr><td colSpan={7} className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                        {liberadasL.length === 0
                                            ? 'Nenhuma nota liberada neste dia.'
                                            : `Nenhuma nota liberada em ${ORIGEM_LABEL[origemLiberadas!]} neste dia.`}
                                    </td></tr>
                                ) : liberadasFiltradas.map(n => (
                                    <tr key={n.id} className="opacity-80 group" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>
                                            <span className="line-through">{n.numero_nota}</span>
                                            {/* Selo CEASA (lembrete, não é divergência) — segue visível após liberar */}
                                            {n.ceasa > 0 && (
                                                <span className="ml-2 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide no-underline"
                                                    style={{ background: p.PURPLE + '22', color: p.PURPLE, border: `1px solid ${p.PURPLE}44` }}
                                                    title="Nota de CEASA">
                                                    {n.ceasa === 3 ? 'CEASA' : `CEASA ${n.ceasa}`}
                                                </span>
                                            )}
                                            {/* Liberada em outro dia, mas o caminhão trouxe hoje */}
                                            {n.recebida_em?.slice(0, 10) === hoje() && n.liberada_em?.slice(0, 10) !== hoje() && (
                                                <span className="ml-2 text-[11px] font-medium px-1.5 py-0.5 rounded no-underline"
                                                    style={{ background: p.GREEN + '22', color: p.GREEN }}
                                                    title={`Liberada no pré-lote em ${n.liberada_em ? new Date(n.liberada_em).toLocaleDateString('pt-BR') : '—'}`}>
                                                    recebida hoje
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>{n.fornecedor.nome}</td>
                                        <td className="px-4 py-3">
                                            <button onClick={() => setCardsId(n.id)} className="flex flex-wrap items-center gap-1" title="Ver histórico de cards">
                                                {n.cards.length === 0
                                                    ? <span className="text-xs" style={{ color: p.MUTED }}>sem divergência</span>
                                                    : n.cards.map(c => <CardBadge key={c.id} card={c} isDark={isDark} />)}
                                            </button>
                                        </td>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(n.loja)}</td>
                                        <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }} title={n.observacao ?? ''}>
                                            {n.observacao || <span style={{ color: p.MUTED }}>—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-sm" style={{ color: p.TEXT }}>{n.liberada_por?.name.split(' ')[0] ?? '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <button onClick={() => setComentariosNota(n)} title="Comentários"
                                                className={`inline-flex items-center gap-1 p-1.5 rounded-lg transition ${n.comentarios_count > 0 ? '' : 'acoes-hover'}`}
                                                style={{ color: n.comentarios_count > 0 ? p.ACCENT : p.MUTED }}>
                                                <Icone path="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.8 9.8 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                                {n.comentarios_count > 0 && <span className="text-xs font-medium">{n.comentarios_count}</span>}
                                            </button>

                                            {/* Editar observação (recebimento/compras/pré-lote) e lembrete CEASA (recebimento) */}
                                            {(can.editarObservacao || can.editarCeasaLiberada) && (
                                                <button onClick={() => setEditarLiberadaNota(n)} title="Editar observação / CEASA"
                                                    className="inline-flex items-center p-1.5 rounded-lg transition acoes-hover"
                                                    style={{ color: p.ACCENT }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.ACCENT + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </button>
                                            )}

                                            {/* Encaminha para o quadro de devoluções com nota e
                                                fornecedor prontos — a pessoa completa o resto. */}
                                            {can.usarDevolucoes && (
                                                <button onClick={() => encaminharParaDevolucao(n)} title="Encaminhar para devolução"
                                                    className="inline-flex items-center p-1.5 rounded-lg transition acoes-hover"
                                                    style={{ color: p.ORANGE }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.ORANGE + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path={ICONE_PARA_DEVOLUCAO} />
                                                </button>
                                            )}

                                            {/* Conferiu errado: devolve ao recebimento para reajuste (pré-lote/recebimento) */}
                                            {can.devolverNota && (
                                                <button onClick={() => devolver(n)} title="Devolver ao recebimento (conferido errado)"
                                                    className="inline-flex items-center p-1.5 rounded-lg transition acoes-hover"
                                                    style={{ color: p.AMBER }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.AMBER + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                                                </button>
                                            )}

                                            {/* Apagar o que já foi liberado é ato de admin — some para os outros papéis */}
                                            {can.excluirNotaLiberada && (
                                                <button onClick={() => excluir(n)} title="Excluir nota liberada"
                                                    className="inline-flex items-center p-1.5 rounded-lg transition acoes-hover"
                                                    style={{ color: p.RED }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="lg:hidden">
                        {liberadasFiltradas.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                {liberadasL.length === 0
                                    ? 'Nenhuma nota liberada neste dia.'
                                    : `Nenhuma nota liberada em ${ORIGEM_LABEL[origemLiberadas!]} neste dia.`}
                            </p>
                        ) : liberadasFiltradas.map(n => (
                            <CartaoLiberada key={n.id} nota={n} can={can} isDark={isDark} p={p}
                                onCards={x => setCardsId(x.id)} onComentar={setComentariosNota}
                                onEditarObs={setEditarLiberadaNota} onDevolucao={encaminharParaDevolucao}
                                onDevolver={devolver} onExcluir={excluir} />
                        ))}
                    </div>
                </div>

                {/* ── Canceladas ──────────────────────────────────────────────── */}
                <div id="secao-canceladas" className="rounded-xl overflow-hidden scroll-mt-20" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                        <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.MUTED }}>
                            Canceladas neste dia
                            <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                                style={{ background: p.ORANGE + '22', color: p.ORANGE, border: `1px solid ${p.ORANGE}33` }}>
                                {canceladasL.length}
                            </span>
                        </h2>
                        <span className="text-xs" style={{ color: p.MUTED }}>NF cancelada pelo fornecedor</span>
                    </div>
                    <div className="hidden lg:block rolagem-x">
                        <table className="min-w-full">
                            <THead colunas={COLS_CANCELADAS} p={p} />
                            <tbody>
                                {canceladasL.length === 0 ? (
                                    <tr><td colSpan={7} className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                        Nenhuma nota cancelada neste dia.
                                    </td></tr>
                                ) : canceladasL.map(n => (
                                    <tr key={n.id} className="opacity-80 group" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>
                                            <span className="line-through">{n.numero_nota}</span>
                                            {n.ceasa > 0 && (
                                                <span className="ml-2 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide no-underline"
                                                    style={{ background: p.PURPLE + '22', color: p.PURPLE, border: `1px solid ${p.PURPLE}44` }}
                                                    title="Nota de CEASA">
                                                    {n.ceasa === 3 ? 'CEASA' : `CEASA ${n.ceasa}`}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>{n.fornecedor.nome}</td>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(n.loja)}</td>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.MUTED }}>
                                            {ORIGEM_LABEL[n.origem]}
                                        </td>
                                        <td className="px-4 py-3 text-sm max-w-[220px] truncate" style={{ color: p.TEXT }} title={n.motivo_cancelamento ?? ''}>
                                            {n.motivo_cancelamento || <span style={{ color: p.MUTED }}>—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-sm" style={{ color: p.TEXT }}>{n.cancelada_por?.name.split(' ')[0] ?? '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <button onClick={() => setComentariosNota(n)} title="Comentários"
                                                className={`inline-flex items-center gap-1 p-1.5 rounded-lg transition ${n.comentarios_count > 0 ? '' : 'acoes-hover'}`}
                                                style={{ color: n.comentarios_count > 0 ? p.ACCENT : p.MUTED }}>
                                                <Icone path="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.8 9.8 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                                {n.comentarios_count > 0 && <span className="text-xs font-medium">{n.comentarios_count}</span>}
                                            </button>

                                            {/* Cancelou por engano: volta para a fila */}
                                            {can.cancelarNota && (
                                                <button onClick={() => descancelar(n)} title="Desfazer cancelamento"
                                                    className="inline-flex items-center p-1.5 rounded-lg transition acoes-hover"
                                                    style={{ color: p.GREEN }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.GREEN + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="lg:hidden">
                        {canceladasL.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                Nenhuma nota cancelada neste dia.
                            </p>
                        ) : canceladasL.map(n => (
                            <CartaoCancelada key={n.id} nota={n} can={can} p={p}
                                onComentar={setComentariosNota} onDescancelar={descancelar} />
                        ))}
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
