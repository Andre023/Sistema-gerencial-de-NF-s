import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';
import { MARCADORES, cartaEmTexto, dinheiro, montarCarta } from '@/lib/campanha';
import Atendidos from '@/Components/campanha/Atendidos';
import Icone from '@/Components/painel/Icone';

/**
 * Um fornecedor sugerido no campo. Vem da planilha de compras (com o
 * faturamento junto) ou, quando ninguém enviou planilha, do cadastro das
 * notas — aí só o nome, e o valor continua sendo digitado à mão.
 */
export interface Sugestao {
    nome: string;
    faturamento: number | null;
}

/** De onde veio a base de faturamento que está valendo. */
interface Base {
    arquivo: string;
    linhas: number;
    enviada_em: string;
    enviado_por: string;
}

interface Props {
    /** O texto salvo desta pessoa — ou o padrão da loja, se ela nunca salvou. */
    texto: string;
    padrao: string;
    temPerfil: boolean;
    fornecedores: Sugestao[];
    base: Base | null;
    limiteDeCaracteres: number;
    percentualSugerido: number;
}

// ─── Dinheiro ──────────────────────────────────────────────────────────────────
//
// O campo guarda CENTAVOS em dígitos ("253625721") e mostra formatado. É o jeito
// que não briga com quem digita: cada tecla entra pela direita, o apagar tira um
// dígito, e não existe estado meio digitado ("2.536.25") para o programa
// interpretar errado.

const centavos = (digitos: string): number | null =>
    digitos === '' ? null : Number(digitos) / 100;

function CampoDinheiro({ digitos, onDigitos, p, id, autoFocus }: {
    digitos: string; onDigitos: (v: string) => void; p: Palette; id: string; autoFocus?: boolean;
}) {
    const valor = centavos(digitos);

    return (
        <input
            id={id}
            inputMode="numeric"
            autoFocus={autoFocus}
            value={valor === null ? '' : dinheiro(valor)}
            onChange={e => onDigitos(e.target.value.replace(/\D/g, '').replace(/^0+/, '').slice(0, 14))}
            placeholder="R$ 0,00"
            className="block w-full rounded-lg text-sm px-3 py-2 outline-none tabular-nums"
            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
        />
    );
}

// ─── Fornecedor ────────────────────────────────────────────────────────────────

const semAcento = (t: string) =>
    t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

function CampoFornecedor({ valor, onValor, onEscolher, onSair, fornecedores, p }: {
    valor: string;
    onValor: (v: string) => void;
    /** Escolheu um da lista — é aqui que o faturamento entra sozinho. */
    onEscolher: (f: Sugestao) => void;
    /** Saiu do campo: última chance de reconhecer o nome digitado à mão. */
    onSair: () => void;
    fornecedores: Sugestao[];
    p: Palette;
}) {
    const [aberto, setAberto] = useState(false);

    // O campo é livre de propósito: o parceiro da campanha pode nem estar na
    // planilha. A lista só sugere — é ela que evita o erro de digitação no nome
    // que vai impresso na carta, e que traz o valor junto.
    const sugestoes = useMemo(() => {
        const busca = semAcento(valor.trim());
        if (busca.length < 2) return [];

        return fornecedores.filter(f => semAcento(f.nome).includes(busca)).slice(0, 8);
    }, [valor, fornecedores]);

    const mostrar = aberto && sugestoes.length > 0 && !(sugestoes.length === 1 && sugestoes[0].nome === valor.trim());

    return (
        <div className="relative">
            <input
                id="fornecedor"
                value={valor}
                onChange={e => { onValor(e.target.value); setAberto(true); }}
                onFocus={() => setAberto(true)}
                // O atraso deixa o clique na sugestão acontecer antes de a
                // lista sumir — sem ele, o mousedown fecha e o clique cai no
                // vazio.
                onBlur={() => setTimeout(() => { setAberto(false); onSair(); }, 120)}
                onKeyDown={e => { if (e.key === 'Escape') setAberto(false); }}
                placeholder="Nome do fornecedor como vai na carta"
                autoComplete="off"
                className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
            />

            {mostrar && (
                <ul className="absolute z-20 mt-1 w-full rounded-lg overflow-hidden shadow-lg max-h-60 overflow-y-auto"
                    style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    {sugestoes.map(f => (
                        <li key={f.nome}>
                            <button
                                type="button"
                                onMouseDown={e => e.preventDefault()}
                                onClick={() => { onEscolher(f); setAberto(false); }}
                                className="w-full flex items-center gap-3 text-left text-sm px-3 py-2 transition"
                                style={{ color: p.TEXT }}
                                onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                            >
                                <span className="truncate">{f.nome}</span>
                                {f.faturamento !== null && (
                                    <span className="ml-auto shrink-0 text-xs tabular-nums" style={{ color: p.MUTED }}>
                                        {dinheiro(f.faturamento)}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// ─── Tela ──────────────────────────────────────────────────────────────────────

export default function Index({
    texto: textoSalvo, padrao, temPerfil, fornecedores, base, limiteDeCaracteres, percentualSugerido,
}: Props) {
    const { auth } = usePage().props;
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const [fornecedor, setFornecedor] = useState('');
    const [faturamentoDig, setFaturamentoDig] = useState('');
    const [investimentoDig, setInvestimentoDig] = useState('');
    /** Os valores desta carta vieram da planilha (e não da mão de alguém). */
    const [veioDaPlanilha, setVeioDaPlanilha] = useState(false);

    const [texto, setTexto] = useState(textoSalvo);
    const [confirmandoRestauro, setConfirmandoRestauro] = useState(false);
    const [ocupado, setOcupado] = useState(false);
    const [copiado, setCopiado] = useState(false);
    const [erro, setErro] = useState('');

    const [planilha, setPlanilha] = useState<File | null>(null);
    const [erroPlanilha, setErroPlanilha] = useState('');

    const areaTexto = useRef<HTMLTextAreaElement>(null);
    const campoArquivo = useRef<HTMLInputElement>(null);

    // Depois de salvar ou restaurar, o servidor manda o texto novo — a tela
    // acompanha. Enquanto ninguém salva, o rascunho é de quem está digitando.
    useEffect(() => setTexto(textoSalvo), [textoSalvo]);

    const faturamento = centavos(faturamentoDig);
    const investimento = centavos(investimentoDig);

    const dados = { fornecedor, faturamento, investimento };
    const paragrafos = useMemo(() => montarCarta(texto, dados), [texto, fornecedor, faturamento, investimento]);

    const completo = fornecedor.trim() !== '' && faturamento !== null && investimento !== null;
    const textoMudou = texto !== textoSalvo;

    const percentual = faturamento && investimento !== null && faturamento > 0
        ? (investimento / faturamento) * 100
        : null;

    // ── Ações ──

    const aplicarPercentual = (pct: number) => {
        if (!faturamento) return;
        setInvestimentoDig(String(Math.round(faturamento * pct))); // pct já em centavos por real
        setVeioDaPlanilha(false);
    };

    /**
     * Escolheu o fornecedor na lista: o nome vai para o campo e, se a planilha
     * souber o faturamento dele, os dois valores já entram preenchidos — o
     * investimento na porcentagem sugerida. Os dois seguem editáveis.
     */
    const escolherFornecedor = (f: Sugestao) => {
        setFornecedor(f.nome);

        if (f.faturamento === null) {
            return;
        }

        setFaturamentoDig(String(Math.round(f.faturamento * 100)));
        setInvestimentoDig(String(Math.round(f.faturamento * percentualSugerido)));
        setVeioDaPlanilha(true);
    };

    /**
     * Digitou o nome inteiro em vez de clicar na sugestão. Se ele está na
     * planilha e o faturamento ainda está vazio, preenche do mesmo jeito.
     *
     * A condição do campo vazio é o que impede a tela de passar por cima de um
     * valor que a pessoa acabou de digitar à mão.
     */
    const completarPeloNome = () => {
        if (faturamentoDig !== '' || fornecedor.trim() === '') {
            return;
        }

        const procurado = semAcento(fornecedor.trim());
        const achado = fornecedores.find(f => f.faturamento !== null && semAcento(f.nome) === procurado);

        if (achado) {
            escolherFornecedor(achado);
        }
    };

    const enviarPlanilha = () => {
        if (!planilha) return;

        setErroPlanilha('');
        setOcupado(true);

        router.post(route('campanha.planilha.importar'), { planilha }, {
            forceFormData: true, // é upload: precisa ir como multipart, não JSON
            preserveScroll: true,
            onError: erros => setErroPlanilha(erros.planilha ?? 'Não foi possível ler a planilha.'),
            onSuccess: () => {
                setPlanilha(null);
                if (campoArquivo.current) campoArquivo.current.value = '';
            },
            onFinish: () => setOcupado(false),
        });
    };

    const removerBase = () => {
        setOcupado(true);
        router.delete(route('campanha.planilha.remover'), {
            preserveScroll: true,
            onFinish: () => setOcupado(false),
        });
    };

    const salvarTexto = () => {
        setOcupado(true);
        router.post(route('campanha.texto.salvar'), { texto },
            { preserveScroll: true, onFinish: () => setOcupado(false) });
    };

    const restaurarTexto = () => {
        setConfirmandoRestauro(false);
        setOcupado(true);
        router.delete(route('campanha.texto.restaurar'),
            { preserveScroll: true, onFinish: () => setOcupado(false) });
    };

    const inserirMarcador = (marcador: string) => {
        const area = areaTexto.current;

        if (!area) {
            setTexto(t => t + marcador);
            return;
        }

        const inicio = area.selectionStart;
        const fim = area.selectionEnd;

        setTexto(t => t.slice(0, inicio) + marcador + t.slice(fim));

        // Devolve o cursor para depois do marcador recém-inserido.
        requestAnimationFrame(() => {
            area.focus();
            area.setSelectionRange(inicio + marcador.length, inicio + marcador.length);
        });
    };

    const copiar = async () => {
        try {
            await navigator.clipboard.writeText(cartaEmTexto(texto, dados));
            setCopiado(true);
            setTimeout(() => setCopiado(false), 2500);
        } catch {
            setErro('O navegador não deixou copiar. Selecione o texto da prévia e copie com Ctrl+C.');
        }
    };

    const baixar = async () => {
        setErro('');
        setOcupado(true);

        try {
            const resposta = await window.axios.post(route('campanha.baixar'), {
                texto,
                fornecedor: fornecedor.trim(),
                faturamento,
                investimento,
            }, { responseType: 'blob' });

            const url = URL.createObjectURL(resposta.data as Blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `Aniversário - ${fornecedor.trim().replace(/[\\/:*?"<>|]+/g, ' ').trim()}.docx`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch {
            setErro('Não foi possível gerar o Word. Tente de novo — se insistir, avise o André.');
        } finally {
            setOcupado(false);
        }
    };

    // ── Estilos repetidos ──

    const rotulo = 'block text-sm font-medium mb-1.5';
    const cartao = { background: p.SURFACE, border: `1px solid ${p.BORDER}` };

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Campanha" />

            <div className="flex-1 w-full py-6 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto transition-colors duration-200"
                style={{ background: p.BG }}>

                <div className="mb-5">
                    <h1 className="text-lg font-semibold" style={{ color: p.TEXT }}>Campanha de aniversário</h1>
                    <p className="text-sm mt-1" style={{ color: p.MUTED }}>
                        Preencha os três dados do fornecedor, confira a carta ao lado e baixe o Word pronto para anexar.
                    </p>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-[380px_minmax(0,1fr)] gap-5 items-start">

                    {/* ── Coluna da esquerda: os dados e o texto ── */}
                    <div className="space-y-5">

                        <section className="rounded-xl p-4 sm:p-5 space-y-4" style={cartao}>
                            <h2 className="text-sm font-semibold" style={{ color: p.TEXT }}>Dados do fornecedor</h2>

                            <div>
                                <label htmlFor="fornecedor" className={rotulo} style={{ color: p.MUTED }}>Fornecedor</label>
                                <CampoFornecedor
                                    valor={fornecedor}
                                    onValor={setFornecedor}
                                    onEscolher={escolherFornecedor}
                                    onSair={completarPeloNome}
                                    fornecedores={fornecedores}
                                    p={p}
                                />
                                {base && (
                                    <p className="text-xs mt-1.5" style={{ color: p.MUTED }}>
                                        {veioDaPlanilha
                                            ? <span style={{ color: p.GREEN }}>
                                                Valores preenchidos pela planilha ({percentualSugerido.toLocaleString('pt-BR')}% de investimento).
                                              </span>
                                            : `Escolha na lista e o faturamento vem da planilha, com ${percentualSugerido.toLocaleString('pt-BR')}% de investimento.`}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="faturamento" className={rotulo} style={{ color: p.MUTED }}>
                                    Faturamento nos últimos 12 meses
                                </label>
                                <CampoDinheiro id="faturamento" digitos={faturamentoDig}
                                    onDigitos={v => { setFaturamentoDig(v); setVeioDaPlanilha(false); }} p={p} />
                            </div>

                            <div>
                                <label htmlFor="investimento" className={rotulo} style={{ color: p.MUTED }}>
                                    Investimento sugerido
                                </label>
                                <CampoDinheiro id="investimento" digitos={investimentoDig}
                                    onDigitos={v => { setInvestimentoDig(v); setVeioDaPlanilha(false); }} p={p} />

                                {/* Atalhos: o valor quase sempre nasce de uma porcentagem do
                                    faturamento, e a conta na calculadora era o passo de fora
                                    da tela. Continua editável na mão. */}
                                <div className="flex flex-wrap items-center gap-1.5 mt-2">
                                    {[1, 1.5, 2, 3].map(pct => (
                                        <button
                                            key={pct}
                                            type="button"
                                            disabled={!faturamento}
                                            onClick={() => aplicarPercentual(pct)}
                                            className="text-xs px-2 py-1 rounded-md transition disabled:opacity-40"
                                            style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}
                                        >
                                            {pct.toLocaleString('pt-BR')}%
                                        </button>
                                    ))}

                                    {percentual !== null && (
                                        <span className="text-xs ml-auto tabular-nums" style={{ color: p.MUTED }}>
                                            {percentual.toLocaleString('pt-BR', { maximumFractionDigits: 2 })}% do faturamento
                                        </span>
                                    )}
                                </div>
                            </div>
                        </section>

                        {/* ── A planilha de compras ──
                            Fica embaixo dos dados de propósito: é preparo, não o
                            trabalho de todo dia. Quem já tem a base enviada passa
                            direto por aqui e só olha a linha de conferência. */}
                        <section className="rounded-xl p-4 sm:p-5" style={cartao}>
                            <h2 className="text-sm font-semibold" style={{ color: p.TEXT }}>Base de faturamento</h2>

                            {base ? (
                                <p className="text-xs mt-1.5 leading-relaxed" style={{ color: p.MUTED }}>
                                    <span className="font-medium" style={{ color: p.TEXT }}>{base.arquivo}</span>
                                    {' · '}{base.linhas.toLocaleString('pt-BR')} fornecedores
                                    {' · '}enviada em {new Date(base.enviada_em).toLocaleDateString('pt-BR')} por {base.enviado_por}
                                </p>
                            ) : (
                                <p className="text-xs mt-1.5 leading-relaxed" style={{ color: p.MUTED }}>
                                    Envie o <span style={{ color: p.TEXT }}>Ranking de Compras</span> (.xlsx) e o faturamento
                                    passa a entrar sozinho ao escolher o fornecedor. Sem ele, dá para digitar os valores à mão.
                                </p>
                            )}

                            <div className="flex flex-wrap items-center gap-2 mt-3">
                                <input
                                    ref={campoArquivo}
                                    type="file"
                                    accept=".xlsx"
                                    onChange={e => { setPlanilha(e.target.files?.[0] ?? null); setErroPlanilha(''); }}
                                    className="block w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:cursor-pointer"
                                    style={{ color: p.MUTED }}
                                />

                                <button
                                    onClick={enviarPlanilha}
                                    disabled={!planilha || ocupado}
                                    className="text-sm font-medium px-3 py-2 rounded-lg transition disabled:opacity-40"
                                    style={{ background: p.ACCENT, color: '#fff' }}
                                >
                                    {ocupado && planilha ? 'Lendo…' : base ? 'Substituir base' : 'Enviar planilha'}
                                </button>

                                {base && !planilha && (
                                    <button
                                        onClick={removerBase}
                                        disabled={ocupado}
                                        className="text-xs px-2.5 py-2 rounded-lg transition disabled:opacity-40"
                                        style={{ color: p.RED, border: `1px solid ${p.RED}55` }}
                                    >
                                        Remover
                                    </button>
                                )}
                            </div>

                            {erroPlanilha && (
                                <p className="text-xs mt-2 leading-relaxed" style={{ color: p.RED }}>{erroPlanilha}</p>
                            )}
                        </section>

                        <section className="rounded-xl p-4 sm:p-5" style={cartao}>
                            <h2 className="text-sm font-semibold" style={{ color: p.TEXT }}>Texto da carta</h2>
                            <p className="text-xs mt-1 mb-3 leading-relaxed" style={{ color: p.MUTED }}>
                                Escreva como preferir. Onde estiver um marcador, entra o dado que você preencheu
                                acima — clique para inserir no ponto do cursor.
                            </p>

                            <div className="flex flex-wrap gap-1.5 mb-2.5">
                                {Object.values(MARCADORES).map(marcador => (
                                    <button
                                        key={marcador}
                                        type="button"
                                        onClick={() => inserirMarcador(marcador)}
                                        className="text-xs px-1.5 py-1 rounded transition"
                                        style={{
                                            background: isDark ? 'rgba(47,129,247,0.12)' : '#eaf2ff',
                                            color: p.ACCENT,
                                            border: `1px solid ${p.ACCENT}33`,
                                        }}
                                    >
                                        {marcador}
                                    </button>
                                ))}
                            </div>

                            <textarea
                                ref={areaTexto}
                                value={texto}
                                onChange={e => setTexto(e.target.value.slice(0, limiteDeCaracteres))}
                                rows={14}
                                spellCheck
                                className="block w-full rounded-lg text-sm px-3 py-2.5 outline-none leading-relaxed resize-y"
                                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
                            />

                            <div className="flex flex-wrap items-center gap-2 mt-3">
                                <button
                                    onClick={salvarTexto}
                                    disabled={ocupado || !textoMudou}
                                    className="text-sm font-medium px-3 py-2 rounded-lg transition disabled:opacity-40"
                                    style={{ background: p.ACCENT, color: '#fff' }}
                                >
                                    Salvar meu texto
                                </button>

                                {confirmandoRestauro ? (
                                    <span className="flex items-center gap-2 text-xs" style={{ color: p.MUTED }}>
                                        Apagar o seu texto salvo?
                                        <button onClick={restaurarTexto} className="px-2 py-1 rounded-md" style={{ color: p.RED, border: `1px solid ${p.RED}55` }}>
                                            Sim, restaurar
                                        </button>
                                        <button onClick={() => setConfirmandoRestauro(false)} className="px-2 py-1 rounded-md" style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}>
                                            Não
                                        </button>
                                    </span>
                                ) : (
                                    <button
                                        onClick={() => (temPerfil ? setConfirmandoRestauro(true) : setTexto(padrao))}
                                        disabled={ocupado || (!temPerfil && texto === padrao)}
                                        className="text-sm px-3 py-2 rounded-lg transition disabled:opacity-40"
                                        style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}
                                    >
                                        Restaurar texto padrão
                                    </button>
                                )}

                                <span className="text-xs ml-auto" style={{ color: textoMudou ? p.AMBER : p.MUTED }}>
                                    {textoMudou ? 'não salvo' : temPerfil ? 'seu texto salvo' : 'texto padrão'}
                                </span>
                            </div>
                        </section>
                    </div>

                    {/* ── Coluna da direita: a carta e a lista de atendidos ──
                        Sem `sticky` desde que a lista entrou aqui: grudada, a
                        coluna inteira ficaria presa no alto e o fim dela — que
                        agora tem conteudo — sairia do alcance da rolagem. */}
                    <div className="space-y-5">
                        <section className="rounded-xl overflow-hidden" style={cartao}>
                            <div className="px-4 py-3 flex items-center justify-between gap-3"
                                style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                <h2 className="text-sm font-semibold" style={{ color: p.TEXT }}>Prévia da carta</h2>
                                <span className="text-xs" style={{ color: p.MUTED }}>
                                    é assim que sai no Word
                                </span>
                            </div>

                            {/* A folha: fundo branco nos dois temas, porque é o papel do
                                documento — e não mais uma superfície da tela. */}
                            <div className="p-4 sm:p-6" style={{ background: isDark ? '#e9eaec' : '#f3f4f6' }}>
                                <div className="mx-auto max-w-[640px] bg-white rounded-md shadow-sm px-7 py-8 sm:px-10 sm:py-11">
                                    {paragrafos.length === 0 ? (
                                        <p className="text-sm text-center text-gray-400 py-10">
                                            O texto está vazio — escreva a carta ao lado.
                                        </p>
                                    ) : paragrafos.map((trechos, i) => (
                                        <p key={i} className="text-[13.5px] leading-[1.65] text-justify text-gray-900 mb-3.5 last:mb-0"
                                            style={{ fontFamily: 'Calibri, Carlito, "Segoe UI", system-ui, sans-serif' }}>
                                            {trechos.map((trecho, j) => (
                                                trecho.valor
                                                    ? <strong key={j} style={{
                                                        color: trecho.vazio ? '#9ca3af' : '#111827',
                                                        fontWeight: trecho.vazio ? 400 : 700,
                                                        background: trecho.vazio ? '#f3f4f6' : 'transparent',
                                                        borderRadius: 3,
                                                        padding: trecho.vazio ? '0 3px' : 0,
                                                      }}>{trecho.texto}</strong>
                                                    : <span key={j}>{trecho.texto}</span>
                                            ))}
                                        </p>
                                    ))}
                                </div>
                            </div>

                            <div className="px-4 py-3 flex flex-wrap items-center gap-2"
                                style={{ borderTop: `1px solid ${p.BORDER}` }}>
                                <button
                                    onClick={baixar}
                                    disabled={!completo || ocupado}
                                    className="inline-flex items-center gap-2 text-sm font-medium px-4 py-2.5 rounded-lg transition disabled:opacity-40"
                                    style={{ background: p.GREEN, color: '#fff' }}
                                >
                                    <Icone path="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                                    {ocupado ? 'Gerando…' : 'Baixar Word'}
                                </button>

                                <button
                                    onClick={copiar}
                                    disabled={!completo}
                                    className="text-sm px-3.5 py-2.5 rounded-lg transition disabled:opacity-40"
                                    style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}
                                >
                                    {copiado ? 'Copiado!' : 'Copiar texto'}
                                </button>

                                {!completo && (
                                    <span className="text-xs" style={{ color: p.MUTED }}>
                                        Falta preencher fornecedor, faturamento e investimento.
                                    </span>
                                )}
                            </div>

                            {erro && (
                                <p className="px-4 pb-3 text-xs" style={{ color: p.RED }}>{erro}</p>
                            )}
                        </section>

                        {/* Logo abaixo da carta, ocupando o vao que sobrava
                            nesta coluna. O botao de incluir aproveita o
                            fornecedor ja preenchido a esquerda — quem acabou de
                            gerar a carta so confirma. */}
                        <Atendidos
                            candidato={{ fornecedor, faturamento, investimento }}
                            percentualSugerido={percentualSugerido}
                            meuId={auth.user.id}
                            souAdmin={auth.user.role === 'admin'}
                            p={p}
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
