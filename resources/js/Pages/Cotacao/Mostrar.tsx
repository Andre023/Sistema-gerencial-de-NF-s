import { ReactNode, useCallback, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';
import { Coluna, Folha, Linha, TipoFolha, corDoColega, mostrar } from '@/lib/cotacao';
import Planilha, { FocoNaFolha } from '@/Components/cotacao/Planilha';
import { useCotacaoAoVivo } from '@/Components/cotacao/useCotacaoAoVivo';
import Avatar from '@/Components/painel/Avatar';

interface Opcao { id: string; nome: string }

interface Props {
    cotacao: { id: number; titulo: string; criada_por: string | null; criada_em: string };
    folhas: Folha[];
    lojas: Opcao[];
    setores: Opcao[];
}

/** As colunas que o sistema cria em cada folha — essas não se apagam. */
const FIXAS: Record<TipoFolha, string[]> = {
    lista: ['produto', 'desc', 'gtin', 'data', 'qtd', 'forn', 'custo', 'venda'],
    previa: ['setor', 'gtin', 'desc', 'valor'],
    resposta: ['gtin', 'desc', 'valor'],
    compra: ['produto', 'desc', 'gtin', 'custo', 'venda', 'forn1', 'preco1', 'vs', 'forn2', 'preco2', 'cotacoes', 'fator', 'quantidade', 'preco_forn', 'fornecedor'],
};

const PASSOS = ['passo-1', 'passo-2', 'passo-3', 'passo-4'];
const CHAVE_RECOLHIDOS = 'cotacao.passosRecolhidos';

const mensagem = (e: any, padrao: string): string =>
    e?.response?.data?.message && e.response.status !== 500 ? e.response.data.message : padrao;

export default function Mostrar({ cotacao, folhas: iniciais, lojas, setores }: Props) {
    const { auth } = usePage().props;
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const vivo = useCotacaoAoVivo(cotacao.id, iniciais, auth.user.id);
    const { folhas, enviar, cliente } = vivo;

    const [ocupado, setOcupado] = useState('');
    const [avisos, setAvisos] = useState<string[]>([]);
    const [confirmandoExclusao, setConfirmandoExclusao] = useState(false);

    // Passos recolhidos: preferência de cada um, guardada neste navegador.
    // Quem está no meio da compra deixa só o passo 4 aberto e não perde isso
    // ao recarregar a página.
    const [recolhidos, setRecolhidos] = useState<Record<string, boolean>>(() => {
        try { return JSON.parse(localStorage.getItem(CHAVE_RECOLHIDOS) ?? '{}') ?? {}; } catch { return {}; }
    });
    const guardarRecolhidos = (novo: Record<string, boolean>) => {
        setRecolhidos(novo);
        try { localStorage.setItem(CHAVE_RECOLHIDOS, JSON.stringify(novo)); } catch { /* aba anônima: só não lembra */ }
    };
    const alternar = (id: string) => guardarRecolhidos({ ...recolhidos, [id]: !recolhidos[id] });
    const todosRecolhidos = PASSOS.every(id => recolhidos[id]);

    const doTipo = (tipo: TipoFolha) => folhas.filter(f => f.tipo === tipo);
    const listas = doTipo('lista');
    const previa = doTipo('previa')[0] ?? null;
    const respostas = doTipo('resposta');
    const compras = doTipo('compra');

    // ── Ações no servidor ──

    const executar = async (rotulo: string, acao: () => Promise<void>) => {
        setOcupado(rotulo);
        setAvisos([]);
        try { await acao(); } finally { setOcupado(''); }
    };

    const importar = (rota: 'cotacao.listas.importar' | 'cotacao.respostas.importar', arquivos: File[]) =>
        executar(rota, async () => {
            const form = new FormData();
            arquivos.forEach(a => form.append('arquivos[]', a));
            form.append('cliente', cliente);
            try {
                const { data } = await window.axios.post(route(rota, cotacao.id), form);
                vivo.adotar(data.folhas);
                setAvisos(data.erros ?? []);
            } catch (e: any) {
                const erros = e?.response?.data?.erros;
                setAvisos(erros?.length ? erros : [mensagem(e, 'Não consegui ler as planilhas.')]);
            }
        });

    const gerar = (rota: 'cotacao.previa.gerar' | 'cotacao.compra.gerar') =>
        executar(rota, async () => {
            try {
                const { data } = await window.axios.post(route(rota, cotacao.id), { cliente });
                vivo.adotar(data.folha ? [data.folha] : data.folhas);
            } catch (e) {
                setAvisos([mensagem(e, 'Não consegui gerar a folha.')]);
            }
        });

    const emBranco = (dados: Record<string, string>) =>
        executar('em-branco', async () => {
            try {
                const { data } = await window.axios.post(route('cotacao.folhas.em-branco', cotacao.id), { ...dados, cliente });
                vivo.adotar([data.folha]);
            } catch (e) {
                setAvisos([mensagem(e, 'Não consegui criar a folha.')]);
            }
        });

    const remover = (folha: Folha) =>
        executar('remover', async () => {
            try {
                await window.axios.delete(route('cotacao.folhas.remover', [cotacao.id, folha.id]), { data: { cliente } });
                vivo.esquecer(folha.id);
            } catch (e) {
                setAvisos([mensagem(e, 'Não consegui remover a folha.')]);
            }
        });

    // O download vai direto do servidor — por isso espera a última alteração
    // chegar lá (o botão fica travado enquanto "salvando").
    const baixar = (url: string) => { window.location.href = url; };

    const renomear = (titulo: string) => {
        if (titulo.trim() && titulo.trim() !== cotacao.titulo) {
            router.patch(route('cotacao.renomear', cotacao.id), { titulo }, { preserveScroll: true, preserveState: true });
        }
    };

    // ── Folhas ──

    const focosDa = (folha: Folha): FocoNaFolha[] =>
        vivo.focos.filter(f => f.folha === folha.id).map(f => ({ l: f.l, c: f.c, nome: f.nome, cor: corDoColega(f.id) }));

    const grade = (folha: Folha, extras?: Partial<Parameters<typeof Planilha>[0]>) => (
        <Planilha
            key={folha.id}
            folha={folha}
            p={p}
            onOps={ops => enviar(folha.id, ops)}
            onDesfazer={() => vivo.desfazer(folha.id)}
            podeDesfazer={vivo.podeDesfazer(folha.id)}
            focos={focosDa(folha)}
            onFoco={(l, c) => vivo.avisarFoco(folha.id, l, c, auth.user.name)}
            colunasFixas={FIXAS[folha.tipo]}
            {...extras}
        />
    );

    // Cores só da tela na planilha de compra: o 1º preço em verde, o "vs custo"
    // vermelho quando o preço passa do custo. Não vão para o Excel.
    const realceDaCompra = useCallback((linha: Linha, coluna: Coluna) => {
        const v = linha.v[coluna.id];
        if (coluna.id === 'preco1' && v !== undefined) return { cor: p.GREEN, b: true };
        if (coluna.id === 'vs' && typeof v === 'string') return { cor: v.startsWith('-') ? p.GREEN : p.RED };
        if (coluna.id === 'forn1' && v === 'sem cotação') return { cor: p.MUTED, i: true };
        return undefined;
    }, [p]);

    const dicaDaCompra = useCallback((linha: Linha) =>
        linha.of?.length ? linha.of.map(o => `${o.f}: R$ ${mostrar(o.p, 'moeda')}`).join('\n') : undefined, []);

    const cartao = { background: p.SURFACE, border: `1px solid ${p.BORDER}` };
    const travado = !!ocupado || vivo.salvando;

    return (
        <AuthenticatedLayout header={null}>
            <Head title={cotacao.titulo} />

            {/* Largura toda da tela: as planilhas têm 14 colunas, e cada
                centímetro a mais é uma rolagem lateral a menos. */}
            <div className="flex-1 w-full py-4 px-3 sm:px-4 space-y-3" style={{ background: p.BG }}>

                {/* ── Cabeçalho ── */}
                <div className="flex flex-wrap items-center gap-3">
                    <Link href={route('cotacao.index')} className="text-sm" style={{ color: p.MUTED }}>← Cotações</Link>
                    <input
                        defaultValue={cotacao.titulo}
                        onBlur={e => renomear(e.currentTarget.value)}
                        onKeyDown={e => e.key === 'Enter' && e.currentTarget.blur()}
                        aria-label="Nome da cotação"
                        className="text-lg font-semibold bg-transparent rounded-md px-1.5 py-0.5 outline-none min-w-0 flex-1 sm:flex-none sm:w-80"
                        style={{ color: p.TEXT, border: '1px solid transparent' }}
                        onFocus={e => (e.currentTarget.style.borderColor = p.INPUT_BORDER)}
                        onBlurCapture={e => (e.currentTarget.style.borderColor = 'transparent')}
                    />
                    <span className="text-xs" style={{ color: vivo.semConexao ? p.RED : vivo.salvando ? p.AMBER : p.MUTED }}>
                        {vivo.semConexao ? 'sem conexão — não salvo' : vivo.salvando ? 'salvando…' : 'tudo salvo'}
                    </span>

                    <div className="ml-auto flex items-center gap-3">
                        {vivo.presentes.length > 0 && (
                            <div className="flex items-center gap-1.5" title="Também estão nesta cotação agora">
                                <span className="text-xs" style={{ color: p.MUTED }}>aqui agora:</span>
                                {vivo.presentes.map(pessoa => (
                                    <Avatar key={pessoa.id} user={pessoa} size={26} ring={corDoColega(pessoa.id)} />
                                ))}
                            </div>
                        )}
                        {confirmandoExclusao ? (
                            <span className="flex items-center gap-2 text-xs" style={{ color: p.MUTED }}>
                                Apagar a cotação inteira?
                                <button onClick={() => router.delete(route('cotacao.excluir', cotacao.id))}
                                    className="px-2 py-1 rounded-md" style={{ color: p.RED, border: `1px solid ${p.RED}55` }}>Sim, apagar</button>
                                <button onClick={() => setConfirmandoExclusao(false)}
                                    className="px-2 py-1 rounded-md" style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}>Não</button>
                            </span>
                        ) : (
                            <button onClick={() => setConfirmandoExclusao(true)} className="text-xs px-2.5 py-1.5 rounded-md"
                                style={{ color: p.RED, border: `1px solid ${p.RED}44` }}>Apagar cotação</button>
                        )}
                    </div>
                </div>

                {/* Atalhos para os passos — a página é comprida. */}
                <nav className="flex flex-wrap gap-1.5 text-xs">
                    {[
                        ['passo-1', `1 · Listas de compra (${listas.length})`],
                        ['passo-2', `2 · Prévia${previa ? ` (${previa.linhas.length})` : ''}`],
                        ['passo-3', `3 · Respostas (${respostas.length})`],
                        ['passo-4', `4 · Planilha de compra${compras.length ? ` (${compras.length})` : ''}`],
                    ].map(([id, texto]) => (
                        <a key={id} href={`#${id}`} onClick={() => recolhidos[id] && alternar(id)}
                            className="px-2.5 py-1 rounded-full" style={{ color: p.TEXT, border: `1px solid ${p.BORDER}`, background: p.SURFACE }}>{texto}</a>
                    ))}
                    <button onClick={() => guardarRecolhidos(Object.fromEntries(PASSOS.map(id => [id, !todosRecolhidos])))}
                        className="ml-auto px-2.5 py-1 rounded-full" style={{ color: p.MUTED, border: `1px solid ${p.BORDER}` }}>
                        {todosRecolhidos ? '▾ Expandir todos' : '▴ Recolher todos'}
                    </button>
                </nav>

                {/* ── 1. Listas de compra ── */}
                <Passo id="passo-1" n={1} recolhido={!!recolhidos['passo-1']} onAlternar={() => alternar('passo-1')} titulo="Listas de compra das lojas" p={p}
                    explicacao="As planilhas de estoque baixo (Mercearia e Bebidas de cada loja). Confira a loja e o setor de cada uma — eu adivinho pelo título. Dá para corrigir qualquer célula aqui mesmo.">
                    <div className="flex flex-wrap items-stretch gap-2">
                        <EnvioDeArquivos p={p} texto="Clique ou arraste as listas de compra (.xlsx)" ocupado={ocupado === 'cotacao.listas.importar'}
                            onArquivos={a => importar('cotacao.listas.importar', a)} />
                        <button onClick={() => emBranco({ tipo: 'lista', setor: 'merc' })} disabled={travado}
                            className="text-sm px-3 py-2 rounded-lg disabled:opacity-40" style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}>
                            + Lista em branco
                        </button>
                    </div>
                    <Abas p={p} folhas={listas} rotulo={f => `${f.nome}`} contagem={f => f.linhas.length}
                        alerta={f => (!f.meta.loja ? 'Escolha a loja' : undefined)}
                        vazio="Nenhuma lista ainda.">
                        {folha => (
                            <>
                                <BarraDaFolha p={p} folha={folha} cotacaoId={cotacao.id} travado={travado} onRemover={() => remover(folha)}>
                                    <Seletor p={p} rotulo="Loja" valor={folha.meta.loja ?? ''} opcoes={lojas} vazio="Escolha…"
                                        onValor={loja => enviar(folha.id, [{ t: 'meta', meta: { loja } }], false)} />
                                    <Seletor p={p} rotulo="Setor" valor={folha.meta.setor ?? ''} opcoes={setores}
                                        onValor={setor => enviar(folha.id, [{ t: 'meta', meta: { setor } }], false)} />
                                    <CampoNome p={p} valor={folha.nome} onNome={nome => enviar(folha.id, [{ t: 'meta', nome }], false)} />
                                </BarraDaFolha>
                                {grade(folha)}
                            </>
                        )}
                    </Abas>
                </Passo>

                {/* ── 2. Prévia ── */}
                <Passo id="passo-2" n={2} recolhido={!!recolhidos['passo-2']} onAlternar={() => alternar('passo-2')} titulo="Lista de cotação para os fornecedores" p={p}
                    explicacao="Junta os produtos das listas sem repetir, com Mercearia e Bebidas separadas. Ajuste o que quiser e baixe o .xlsx para mandar aos fornecedores. Atualizar de novo traz os produtos novos das listas e mantém o que você mudou aqui.">
                    <div className="flex flex-wrap items-center gap-2">
                        <button onClick={() => gerar('cotacao.previa.gerar')} disabled={travado || !listas.length}
                            className="text-sm font-medium px-3.5 py-2 rounded-lg disabled:opacity-40" style={{ background: p.ACCENT, color: '#fff' }}>
                            {ocupado === 'cotacao.previa.gerar' ? 'Gerando…' : previa ? 'Atualizar prévia com as listas' : 'Gerar prévia'}
                        </button>
                        <button onClick={() => baixar(route('cotacao.exportar.lista', cotacao.id))} disabled={!previa || travado}
                            className="text-sm font-medium px-3.5 py-2 rounded-lg disabled:opacity-40" style={{ background: p.GREEN, color: '#fff' }}>
                            Baixar lista de cotação (.xlsx)
                        </button>
                        {previa && setores.map(s => (
                            <span key={s.id} className="text-xs px-2 py-1 rounded-md tabular-nums" style={{ color: p.MUTED, border: `1px solid ${p.BORDER}` }}>
                                {s.nome}: <b style={{ color: p.TEXT }}>{previa.linhas.filter(l => l.v.setor === s.nome).length}</b>
                            </span>
                        ))}
                        {!listas.length && <span className="text-xs" style={{ color: p.MUTED }}>Coloque as listas de compra no passo 1.</span>}
                    </div>
                    {previa && grade(previa)}
                </Passo>

                {/* ── 3. Respostas ── */}
                <Passo id="passo-3" n={3} recolhido={!!recolhidos['passo-3']} onAlternar={() => alternar('passo-3')} titulo="Cotações devolvidas pelos fornecedores" p={p}
                    explicacao={'As planilhas que voltaram com a coluna "Valor". O nome do fornecedor vem do campo "Fornecedor:" — ou do nome do arquivo. Quem passou preço por telefone: "Digitar resposta" cria a folha com os produtos da prévia.'}>
                    <div className="flex flex-wrap items-stretch gap-2">
                        <EnvioDeArquivos p={p} texto="Clique ou arraste as cotações preenchidas (.xlsx)" ocupado={ocupado === 'cotacao.respostas.importar'}
                            onArquivos={a => importar('cotacao.respostas.importar', a)} />
                        <NovaResposta p={p} travado={travado || !previa} onCriar={fornecedor => emBranco({ tipo: 'resposta', fornecedor })} />
                    </div>
                    <Abas p={p} folhas={respostas} rotulo={f => f.meta.fornecedor || f.nome}
                        contagem={f => f.linhas.filter(l => typeof l.v.valor === 'number').length}
                        alerta={f => (respostas.filter(o => (o.meta.fornecedor || o.nome).toLowerCase() === (f.meta.fornecedor || f.nome).toLowerCase()).length > 1 ? 'Fornecedor repetido — vale o menor preço' : undefined)}
                        vazio="Nenhuma resposta ainda.">
                        {folha => (
                            <>
                                <BarraDaFolha p={p} folha={folha} cotacaoId={cotacao.id} travado={travado} onRemover={() => remover(folha)}>
                                    <CampoNome p={p} rotulo="Fornecedor" valor={folha.meta.fornecedor || folha.nome}
                                        onNome={nome => enviar(folha.id, [{ t: 'meta', nome, meta: { fornecedor: nome } }], false)} />
                                    <span className="text-xs" style={{ color: p.MUTED }}>
                                        {folha.linhas.filter(l => typeof l.v.valor === 'number').length} com preço · {folha.linhas.filter(l => l.v.valor === undefined).length} sem preço
                                    </span>
                                </BarraDaFolha>
                                {grade(folha)}
                            </>
                        )}
                    </Abas>
                </Passo>

                {/* ── 4. Planilha de compra ── */}
                <Passo id="passo-4" n={4} recolhido={!!recolhidos['passo-4']} onAlternar={() => alternar('passo-4')} titulo="Planilha de compra: menores preços" p={p}
                    explicacao="Para cada produto, o 1º e o 2º menor preço (caso o estoque do primeiro acabe). Fator, Quantidade, Preço for e Fornecedor são para preencher na hora da compra — atualizar com uma resposta nova muda os preços e mantém o que vocês escreveram e pintaram.">
                    <div className="flex flex-wrap items-center gap-2">
                        <button onClick={() => gerar('cotacao.compra.gerar')} disabled={travado || !listas.length}
                            className="text-sm font-medium px-3.5 py-2 rounded-lg disabled:opacity-40" style={{ background: p.ACCENT, color: '#fff' }}>
                            {ocupado === 'cotacao.compra.gerar' ? 'Gerando…' : compras.length ? 'Atualizar com as respostas' : 'Gerar planilha de compra'}
                        </button>
                        <button onClick={() => baixar(route('cotacao.exportar.compra', cotacao.id))} disabled={!compras.length || travado}
                            className="text-sm font-medium px-3.5 py-2 rounded-lg disabled:opacity-40" style={{ background: p.GREEN, color: '#fff' }}>
                            Baixar planilha de compra (.xlsx)
                        </button>
                        {!respostas.length && <span className="text-xs" style={{ color: p.MUTED }}>Sem respostas ainda: os produtos saem "sem cotação".</span>}
                    </div>
                    <Abas p={p} folhas={compras} rotulo={f => f.nome} contagem={f => f.linhas.length}
                        alerta={() => undefined} vazio="Gere a planilha de compra depois de colocar as respostas.">
                        {folha => (
                            <>
                                <ResumoDaCompra p={p} folha={folha} />
                                {grade(folha, { realce: realceDaCompra, dica: dicaDaCompra, altura: '70vh' })}
                            </>
                        )}
                    </Abas>
                </Passo>
            </div>

            {/* Avisos fixos no rodapé da tela, e não no topo da página: quem está
                no passo 4 com a planilha aberta não veria um aviso lá em cima. */}
            {(vivo.semConexao || avisos.length > 0 || vivo.erro) && (
                <div className="fixed bottom-4 left-1/2 -translate-x-1/2 z-50 w-[min(640px,calc(100vw-32px))] space-y-2">
                    {vivo.semConexao && (
                        <div className="rounded-lg px-4 py-3 text-sm shadow-lg" style={{ background: p.SURFACE, color: p.TEXT, border: `1px solid ${p.AMBER}` }}>
                            <b style={{ color: p.AMBER }}>Sem conexão com o servidor.</b>{' '}
                            Suas alterações estão guardadas nesta tela e vão ser enviadas sozinhas quando a conexão voltar — não feche a aba.
                        </div>
                    )}
                    {(avisos.length > 0 || vivo.erro) && (
                        <div className="rounded-lg px-4 py-3 text-sm shadow-lg flex items-start gap-3" style={{ background: p.SURFACE, color: p.RED, border: `1px solid ${p.RED}` }}>
                            <div className="flex-1 space-y-0.5">
                                {[...avisos, ...(vivo.erro ? [vivo.erro] : [])].map((a, i) => <p key={i}>{a}</p>)}
                            </div>
                            <button onClick={() => { setAvisos([]); vivo.limparErro(); }} className="text-xs underline shrink-0" style={{ color: p.MUTED }}>fechar</button>
                        </div>
                    )}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

// ─── Peças ─────────────────────────────────────────────────────────────────────

function Passo({ id, n, titulo, explicacao, p, recolhido, onAlternar, children }: {
    id: string; n: number; titulo: string; explicacao: string; p: Palette; recolhido: boolean; onAlternar: () => void; children: ReactNode;
}) {
    return (
        <section id={id} className={`rounded-xl px-3 sm:px-4 scroll-mt-4 ${recolhido ? 'py-2.5' : 'py-3.5 space-y-3'}`}
            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            {/* O cabeçalho inteiro abre e fecha — alvo grande, como no acordeão do Excel. */}
            <button type="button" onClick={onAlternar} aria-expanded={!recolhido} aria-controls={`${id}-conteudo`}
                className="w-full text-left flex items-start gap-2.5">
                <span className="w-6 h-6 rounded-full inline-grid place-items-center text-xs shrink-0" style={{ background: p.ACCENT, color: '#fff' }}>{n}</span>
                <span className="flex-1 min-w-0">
                    <span className="block text-base font-semibold" style={{ color: p.TEXT }}>{titulo}</span>
                    {!recolhido && <span className="block text-sm mt-1 max-w-[85ch]" style={{ color: p.MUTED }}>{explicacao}</span>}
                </span>
                <span className="shrink-0 text-xs px-2 py-1 rounded-md" style={{ color: p.MUTED, border: `1px solid ${p.BORDER}` }}>
                    {recolhido ? '▾ Expandir' : '▴ Recolher'}
                </span>
            </button>
            {/* Escondido, e não desmontado: ao expandir de novo, a aba escolhida,
                a busca e a seleção da planilha continuam onde estavam. */}
            <div id={`${id}-conteudo`} className={recolhido ? 'hidden' : 'space-y-3'}>{children}</div>
        </section>
    );
}

function EnvioDeArquivos({ p, texto, ocupado, onArquivos }: { p: Palette; texto: string; ocupado: boolean; onArquivos: (a: File[]) => void }) {
    const [sobre, setSobre] = useState(false);
    const campo = useRef<HTMLInputElement>(null);

    return (
        <label
            className="flex-1 min-w-[240px] flex items-center justify-center text-sm text-center rounded-lg px-4 py-3 cursor-pointer transition"
            style={{ border: `2px dashed ${sobre ? p.ACCENT : p.INPUT_BORDER}`, color: sobre ? p.TEXT : p.MUTED, background: sobre ? p.ACCENT + '12' : 'transparent' }}
            onDragOver={e => { e.preventDefault(); setSobre(true); }}
            onDragLeave={() => setSobre(false)}
            onDrop={e => { e.preventDefault(); setSobre(false); if (e.dataTransfer.files.length) onArquivos([...e.dataTransfer.files]); }}
        >
            <input ref={campo} type="file" multiple accept=".xlsx,.csv" className="hidden"
                onChange={e => { if (e.target.files?.length) onArquivos([...e.target.files]); if (campo.current) campo.current.value = ''; }} />
            {ocupado ? 'Lendo as planilhas…' : texto}
        </label>
    );
}

function Abas({ p, folhas, rotulo, contagem, alerta, vazio, children }: {
    p: Palette; folhas: Folha[]; rotulo: (f: Folha) => string; contagem: (f: Folha) => number;
    alerta: (f: Folha) => string | undefined; vazio: string; children: (f: Folha) => ReactNode;
}) {
    const [escolhida, setEscolhida] = useState<number | null>(null);
    const atual = folhas.find(f => f.id === escolhida) ?? folhas[0];

    if (!atual) return <p className="text-sm" style={{ color: p.MUTED }}>{vazio}</p>;

    return (
        <div className="space-y-2.5">
            <div className="flex flex-wrap gap-1" role="tablist" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                {folhas.map(f => {
                    const ativa = f.id === atual.id;
                    const a = alerta(f);
                    return (
                        <button key={f.id} role="tab" aria-selected={ativa} onClick={() => setEscolhida(f.id)}
                            className="text-sm px-3 py-1.5 rounded-t-lg -mb-px max-w-[280px] truncate"
                            title={a ?? rotulo(f)}
                            style={{
                                color: ativa ? p.ACCENT : p.MUTED,
                                background: ativa ? p.SURFACE : 'transparent',
                                border: `1px solid ${ativa ? p.BORDER : 'transparent'}`,
                                borderBottomColor: ativa ? p.SURFACE : 'transparent',
                                fontWeight: ativa ? 600 : 400,
                            }}>
                            {a && <span style={{ color: p.AMBER }}>● </span>}
                            {rotulo(f)}
                            <small className="ml-1.5 font-mono tabular-nums" style={{ color: p.MUTED }}>{contagem(f)}</small>
                        </button>
                    );
                })}
            </div>
            {children(atual)}
        </div>
    );
}

function BarraDaFolha({ p, folha, cotacaoId, travado, onRemover, children }: {
    p: Palette; folha: Folha; cotacaoId: number; travado: boolean; onRemover: () => void; children: ReactNode;
}) {
    const [confirmando, setConfirmando] = useState(false);

    return (
        <div className="flex flex-wrap items-end gap-2.5">
            {children}
            <div className="ml-auto flex items-center gap-2">
                {folha.arquivo && <span className="text-xs truncate max-w-[220px]" style={{ color: p.MUTED }} title={folha.arquivo}>{folha.arquivo}</span>}
                <button onClick={() => { window.location.href = route('cotacao.folhas.exportar', [cotacaoId, folha.id]); }} disabled={travado}
                    className="text-xs px-2.5 py-1.5 rounded-md disabled:opacity-40" style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}>
                    Baixar esta folha
                </button>
                {confirmando ? (
                    <>
                        <button onClick={() => { setConfirmando(false); onRemover(); }} className="text-xs px-2.5 py-1.5 rounded-md"
                            style={{ color: p.RED, border: `1px solid ${p.RED}55` }}>Sim, remover</button>
                        <button onClick={() => setConfirmando(false)} className="text-xs px-2.5 py-1.5 rounded-md"
                            style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}>Não</button>
                    </>
                ) : (
                    <button onClick={() => setConfirmando(true)} disabled={travado} className="text-xs px-2.5 py-1.5 rounded-md disabled:opacity-40"
                        style={{ color: p.RED, border: `1px solid ${p.RED}44` }}>Remover</button>
                )}
            </div>
        </div>
    );
}

function Seletor({ p, rotulo, valor, opcoes, vazio, onValor }: {
    p: Palette; rotulo: string; valor: string; opcoes: Opcao[]; vazio?: string; onValor: (v: string) => void;
}) {
    return (
        <label className="flex flex-col text-xs gap-1" style={{ color: p.MUTED }}>
            {rotulo}
            <select value={valor} onChange={e => onValor(e.target.value)} className="text-sm rounded-md py-1.5 pl-2 pr-8"
                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${!valor ? p.AMBER : p.INPUT_BORDER}` }}>
                {!valor && <option value="">{vazio ?? 'Escolha…'}</option>}
                {opcoes.map(o => <option key={o.id} value={o.id}>{o.nome}</option>)}
            </select>
        </label>
    );
}

function CampoNome({ p, rotulo = 'Nome', valor, onNome }: { p: Palette; rotulo?: string; valor: string; onNome: (v: string) => void }) {
    return (
        <label className="flex flex-col text-xs gap-1" style={{ color: p.MUTED }}>
            {rotulo}
            <input key={valor} defaultValue={valor}
                onBlur={e => { const v = e.currentTarget.value.trim(); if (v && v !== valor) onNome(v); }}
                onKeyDown={e => e.key === 'Enter' && e.currentTarget.blur()}
                className="text-sm rounded-md px-2 py-1.5 w-60 outline-none"
                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
        </label>
    );
}

function NovaResposta({ p, travado, onCriar }: { p: Palette; travado: boolean; onCriar: (fornecedor: string) => void }) {
    const [aberto, setAberto] = useState(false);
    const [nome, setNome] = useState('');

    if (!aberto) {
        return (
            <button onClick={() => setAberto(true)} disabled={travado} title={travado ? 'Gere a prévia antes' : undefined}
                className="text-sm px-3 py-2 rounded-lg disabled:opacity-40" style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}>
                + Digitar resposta
            </button>
        );
    }

    const criar = () => { if (nome.trim()) { onCriar(nome.trim()); setNome(''); setAberto(false); } };

    return (
        <div className="flex items-center gap-2">
            <input autoFocus value={nome} onChange={e => setNome(e.target.value)} placeholder="Nome do fornecedor"
                onKeyDown={e => { if (e.key === 'Enter') criar(); if (e.key === 'Escape') setAberto(false); }}
                className="text-sm rounded-md px-2.5 py-2 w-56 outline-none"
                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
            <button onClick={criar} disabled={!nome.trim()} className="text-sm px-3 py-2 rounded-lg disabled:opacity-40" style={{ background: p.ACCENT, color: '#fff' }}>Criar</button>
            <button onClick={() => setAberto(false)} className="text-sm px-2 py-2" style={{ color: p.MUTED }}>Cancelar</button>
        </div>
    );
}

/** O resumo da loja e setor: quantos produtos, quantos sem cotação e quantos já têm quantidade. */
function ResumoDaCompra({ p, folha }: { p: Palette; folha: Folha }) {
    const resumo = useMemo(() => ({
        sem: folha.linhas.filter(l => l.v.forn1 === 'sem cotação').length,
        comQtd: folha.linhas.filter(l => l.v.quantidade !== undefined).length,
    }), [folha.linhas]);

    const chip = (rotulo: string, valor: number, cor?: string) => (
        <span className="text-xs px-2 py-1 rounded-md tabular-nums" style={{ color: p.MUTED, border: `1px solid ${p.BORDER}` }}>
            {rotulo}: <b style={{ color: cor ?? p.TEXT }}>{valor}</b>
        </span>
    );

    return (
        <div className="flex flex-wrap items-center gap-2">
            {chip('Produtos', folha.linhas.length)}
            {chip('Sem cotação', resumo.sem, resumo.sem ? p.AMBER : undefined)}
            {chip('Com quantidade', resumo.comQtd)}
            <span className="text-xs" style={{ color: p.MUTED }}>Passe o mouse numa linha para ver o preço de todos os fornecedores.</span>
        </div>
    );
}
