import { lazy, Suspense, useEffect, useMemo, useRef, useState } from 'react';
import {
    differenceInCalendarDays, format, isToday, isYesterday, parseISO,
} from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { PessoaChat } from '@/types';
import { Palette } from '@/lib/tema';
import Avatar from '@/Components/painel/Avatar';
import Icone from '@/Components/painel/Icone';
import Bolha from './Bolha';
import { useChat } from './ChatProvider';

/**
 * O seletor entra num pedaço à parte, baixado só quando alguém clica no rosto.
 *
 * Ele carrega o catálogo inteiro (emojis-chat.json). Importado direto, esse
 * catálogo ia junto do layout — que TODA página do sistema baixa, inclusive
 * quem nunca abre o chat. Foi exatamente por isso que os avatares deixaram de
 * usar um mapa de imagens no bundle (ver lib/emoji.ts): 82 KB em toda página
 * para servir um punhado de desenhos.
 */
const SeletorEmoji = lazy(() => import('./SeletorEmoji'));

/** Rótulo do papel embaixo do nome — ajuda a saber com quem se está falando. */
const PAPEL: Record<string, string> = {
    recebimento: 'Recebimento',
    pre_lote: 'Pré-lote',
    compras: 'Compras',
    visitante: 'Visitante',
    admin: 'Administrador',
};

/**
 * Os tipos de imagem que a área de transferência pode entregar E o servidor
 * aceita (Mensagem::EXTENSOES). O valor é a extensão que vamos dar ao arquivo.
 *
 * Conferir aqui, e não deixar o servidor recusar depois, é o que transforma
 * "Formato não aceito" num aviso que diz o que fazer.
 */
const COLAVEIS: Record<string, string> = {
    'image/png':  'png',
    'image/jpeg': 'jpg',
    'image/webp': 'webp',
};

/**
 * "print-14-08-2026-113542.png"
 *
 * A área de transferência entrega todo print como "image.png". Com três prints
 * na mesma conversa, todos com o mesmo nome, não dá para saber qual é qual
 * depois de baixados — o carimbo de hora resolve.
 */
function nomeDePrint(extensao: string): string {
    const d = new Date();
    const dd = (n: number) => String(n).padStart(2, '0');

    return `print-${dd(d.getDate())}-${dd(d.getMonth() + 1)}-${d.getFullYear()}`
         + `-${dd(d.getHours())}${dd(d.getMinutes())}${dd(d.getSeconds())}.${extensao}`;
}

/**
 * A chave que diz se duas mensagens são do mesmo dia.
 *
 * 'yyyy-MM-dd' no fuso de QUEM ESTÁ OLHANDO — que é o certo aqui: a divisória
 * responde "isso foi em que dia para mim?", e não em que dia foi no servidor.
 */
function diaDe(iso: string): string {
    try { return format(parseISO(iso), 'yyyy-MM-dd'); } catch { return ''; }
}

/**
 * O texto da divisória de data.
 *
 * Três formas, da mais útil para a mais precisa:
 *
 *   Hoje / Ontem      — o que responde 90% das vezes, e sem fazer contar
 *   segunda-feira     — dentro da semana o nome do dia situa melhor que a data
 *   14 de agosto      — daí para trás, a data por extenso
 *
 * O caso do ano cheio quase não acontece com a janela de três semanas do chat
 * (Mensagem::DIAS_DE_VIDA): mensagem mais velha que isso já não existe. Ele
 * fica por segurança — o prazo é uma constante, e constante muda.
 */
function rotuloDoDia(iso: string): string {
    try {
        const d = parseISO(iso);

        if (isToday(d)) return 'Hoje';
        if (isYesterday(d)) return 'Ontem';

        if (differenceInCalendarDays(new Date(), d) < 7) {
            return format(d, 'EEEE', { locale: ptBR });
        }

        return format(d, "d 'de' MMMM 'de' yyyy", { locale: ptBR });
    } catch {
        return '';
    }
}

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
        mensagens, carregandoConversa, temAntigas, lidaPeloOutroAte, leituraAoAbrir, enviando, erro,
        outroDigitando,
        fecharConversa, enviar, carregarAntigas, limparErro, reagir, avisarQueDigito,
    } = useChat();

    const [texto, setTexto]     = useState('');
    const [arquivo, setArquivo] = useState<File | null>(null);
    /** Recusa do Ctrl+V (formato que não dá para mandar) — separada do erro de envio. */
    const [aviso, setAviso]     = useState<string | null>(null);
    const [emojiAberto, setEmojiAberto] = useState(false);
    /** Miniatura do que está prendido, para o print colado ter confirmação visual. */
    const [previa, setPrevia]   = useState<string | null>(null);

    const fimRef     = useRef<HTMLDivElement>(null);
    const corpoRef   = useRef<HTMLDivElement>(null);
    const arquivoRef = useRef<HTMLInputElement>(null);
    const textoRef   = useRef<HTMLTextAreaElement>(null);

    /*
     * O campo cresce com o que está escrito, em vez de rolar por dentro.
     *
     * Antes ele parava de crescer na 4ª linha e virava uma caixinha com barra de
     * rolagem: quem escreve um recado de seis linhas não conseguia reler o que
     * tinha escrito sem rolar dentro de um campo de 3 cm.
     *
     * O 'auto' antes de medir não é enfeite. `scrollHeight` nunca é menor que a
     * altura atual do elemento — sem zerar primeiro, o campo cresceria ao
     * digitar e nunca mais encolheria ao apagar.
     *
     * O teto é o tamanho da tela, não um número de linhas: acima disso o campo
     * comeria a conversa inteira, e aí sim volta a rolagem. Na prática são mais
     * de vinte linhas — nenhum recado daqui chega perto.
     */
    useEffect(() => {
        const campo = textoRef.current;
        if (!campo) return;

        const teto = Math.round(window.innerHeight * 0.4);

        campo.style.height = 'auto';

        /*
         * A borda precisa entrar na conta.
         *
         * `scrollHeight` mede conteúdo + padding, SEM a borda. Mas o Tailwind põe
         * `box-sizing: border-box` em tudo, então o `height` que atribuímos tem
         * de incluir a borda. Sem somá-la, o campo fica 2px curto e a última
         * linha aparece cortada pela metade — com barra de rolagem escondida
         * para um vão de dois pixels.
         *
         * offsetHeight − clientHeight é exatamente a borda (os dois já contam o
         * padding), então não precisamos ler CSS nem chutar o valor.
         */
        const borda = campo.offsetHeight - campo.clientHeight;

        // Guardado ANTES de mexer na altura: depois de atribuir, o scrollHeight
        // passa a descrever a caixa nova, e a comparação com o teto viraria uma
        // pergunta sobre o que acabamos de escrever nela.
        const precisa = campo.scrollHeight + borda;

        campo.style.height = `${Math.min(precisa, teto)}px`;
        campo.style.overflowY = precisa > teto ? 'auto' : 'hidden';
    }, [texto]);

    /*
     * A miniatura do anexo pendente.
     *
     * O nome de um print é gerado por nós e não diz nada ("print-14-08-...").
     * Sem a miniatura, a única confirmação de que o Ctrl+V pegou a imagem certa
     * seria mandar e ver — que é tarde demais.
     */
    useEffect(() => {
        if (!arquivo?.type.startsWith('image/')) {
            setPrevia(null);
            return;
        }

        const url = URL.createObjectURL(arquivo);
        setPrevia(url);

        // Sem o revoke, cada print colado (e trocado) deixa megabytes presos na
        // memória da aba até ela ser fechada.
        return () => URL.revokeObjectURL(url);
    }, [arquivo]);

    /**
     * A primeira mensagem que eu ainda não tinha lido ao abrir — o ponto onde a
     * conversa deve começar, e onde entra a divisória.
     *
     * Vem do id e não de uma contagem: contar "as N últimas" quebraria assim
     * que a paginação entregasse menos mensagens do que o não lido.
     *
     * null quando estava tudo lido (aí a conversa abre no fim, como sempre).
     */
    const primeiraNaoLida = useMemo(() => {
        if (!mensagens.length) return null;

        return mensagens.find(m => m.id > leituraAoAbrir && m.autor_id !== meuId)?.id ?? null;
    }, [mensagens, leituraAoAbrir, meuId]);

    /**
     * Onde entram as divisórias de data: id da mensagem → rótulo do dia dela.
     *
     * Calculado UMA vez por mudança na lista, e não dentro do `.map()` do
     * desenho. Feito lá, cada uma das 40 mensagens compararia a sua data com a
     * da anterior a cada redesenho — e a conversa redesenha a cada tecla que se
     * digita no campo de baixo, a cada ✓✓ que chega, a cada reação.
     *
     * Só a PRIMEIRA mensagem de cada dia entra no mapa; as outras não têm
     * divisória e nem aparecem aqui.
     */
    const inicioDeDia = useMemo(() => {
        const mapa = new Map<number, string>();
        let anterior = '';

        for (const m of mensagens) {
            const dia = diaDe(m.created_at);

            if (!dia || dia === anterior) continue;

            mapa.set(m.id, rotuloDoDia(m.created_at));
            anterior = dia;
        }

        return mapa;
    }, [mensagens]);

    const naoLidaRef = useRef<HTMLDivElement>(null);

    /*
     * Onde a conversa abre.
     *
     * Antes abria no COMEÇO do histórico: quem tinha cinco mensagens novas
     * precisava rolar até o fim para achá-las — e o mais recente, que é o que
     * importa, era o mais escondido.
     *
     * Agora para na primeira não lida. Sem nenhuma, vai para o fim.
     *
     * O `jaPosicionou` existe porque isto tem de acontecer UMA vez por
     * conversa. Sem ele, cada mensagem nova puxaria a tela de volta para a
     * divisória, no meio da leitura de quem estava rolando.
     */
    const jaPosicionou = useRef(false);

    useEffect(() => { jaPosicionou.current = false; }, [pessoa.id]);

    useEffect(() => {
        const corpo = corpoRef.current;
        if (!corpo || carregandoConversa || !mensagens.length) return;

        if (!jaPosicionou.current) {
            jaPosicionou.current = true;

            if (naoLidaRef.current) {
                // 'start' põe a divisória no alto: a primeira não lida fica na
                // linha de cima e o resto se lê para baixo, na ordem natural.
                naoLidaRef.current.scrollIntoView({ block: 'start' });
            } else {
                fimRef.current?.scrollIntoView({ block: 'end' });
            }

            return;
        }

        /*
         * Daqui em diante, só acompanha o fim se a pessoa JÁ estava lá. Quem
         * subiu para reler algo antigo não quer ser puxado de volta a cada
         * mensagem que chega.
         */
        const perto = corpo.scrollHeight - corpo.scrollTop - corpo.clientHeight < 120;

        if (perto) fimRef.current?.scrollIntoView({ block: 'end' });
    }, [mensagens, carregandoConversa]);

    /**
     * Ctrl+V com um print na área de transferência.
     *
     * É o anexo mais comum num sistema assim: a pessoa recorta a tela do ERP e
     * quer mandar. Sem isto, são quatro passos (salvar em arquivo, achar a
     * pasta, abrir o seletor, escolher) para o que devia ser um.
     *
     * Só intercepta quando há IMAGEM na área de transferência — colar texto
     * segue funcionando como sempre, inclusive número de nota copiado da fila.
     *
     * O ouvinte fica no painel, e NÃO no documento: no documento, colar dentro
     * de um campo da tela de notas também grudaria a imagem aqui, numa conversa
     * que a pessoa nem estava olhando.
     */
    const colar = (e: React.ClipboardEvent) => {
        const itens = Array.from(e.clipboardData?.items ?? []);
        const imagem = itens.find(i => i.kind === 'file' && i.type.startsWith('image/'));

        if (!imagem) return; // texto: deixa o navegador fazer o de sempre

        const extensao = COLAVEIS[imagem.type];

        if (!extensao) {
            e.preventDefault();
            setAviso('Esse tipo de imagem não pode ser colado. Salve como PNG ou JPG e anexe pelo clipe.');
            return;
        }

        const bruto = imagem.getAsFile();
        if (!bruto) return;

        e.preventDefault();
        setAviso(null);

        setArquivo(new File([bruto], nomeDePrint(extensao), { type: imagem.type }));
    };

    /**
     * Põe o emoji ONDE O CURSOR ESTÁ, e não no fim do texto.
     *
     * Quem escreveu "confirmado a nota chegou" e voltou o cursor para depois de
     * "confirmado" quer o emoji ali. Emenda no fim é o tipo de detalhe que faz
     * a pessoa desistir do seletor e voltar a digitar sem emoji.
     *
     * Devolve o foco ao campo com o cursor logo depois do que foi inserido —
     * senão o próximo caractere digitado iria parar no começo do texto.
     */
    const inserirEmoji = (emoji: string) => {
        const campo = textoRef.current;

        if (!campo) {
            setTexto(t => t + emoji);
            return;
        }

        const inicio = campo.selectionStart ?? campo.value.length;
        const fim    = campo.selectionEnd ?? inicio;

        setTexto(t => t.slice(0, inicio) + emoji + t.slice(fim));

        // O cursor só pode ser movido depois de o React redesenhar o valor —
        // daí o requestAnimationFrame em vez de mexer na hora.
        requestAnimationFrame(() => {
            campo.focus();
            const pos = inicio + emoji.length;
            campo.setSelectionRange(pos, pos);
        });
    };

    const tirarAnexo = () => {
        setArquivo(null);
        setAviso(null);
        if (arquivoRef.current) arquivoRef.current.value = '';
    };

    const submeter = async (e: React.FormEvent) => {
        e.preventDefault();
        if (enviando || (!texto.trim() && !arquivo)) return;

        const t = texto;
        const a = arquivo;

        // Limpa antes de a resposta chegar: a bolha otimista já está na tela, e
        // segurar o campo faria a pessoa achar que não enviou.
        setTexto('');
        tirarAnexo();
        setEmojiAberto(false);

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
                    <div key={m.id}>
                        {/* ── A divisória de data ──
                            Vem ANTES da de "não lidas" quando as duas caem na
                            mesma mensagem: primeiro o dia começa, depois se diz
                            onde a leitura parou dentro dele.

                            A pílula é centrada e discreta de propósito: ela é
                            referência, não conteúdo — quem está lendo a conversa
                            passa o olho por ela sem parar. */}
                        {inicioDeDia.has(m.id) && (
                            <div className="flex justify-center py-2">
                                <span className="text-[10px] font-medium px-2.5 py-1 rounded-full"
                                    style={{ background: p.HOVER_ROW, color: p.MUTED }}>
                                    {inicioDeDia.get(m.id)}
                                </span>
                            </div>
                        )}

                        {m.id === primeiraNaoLida && (
                            // A divisória explica por que a conversa não abriu
                            // no fim. Sem ela, a rolagem parada no meio pareceria
                            // defeito.
                            <div ref={naoLidaRef} className="flex items-center gap-2 py-2">
                                <span className="flex-1 h-px" style={{ background: p.GREEN, opacity: 0.4 }} />
                                <span className="text-[10px] font-semibold uppercase tracking-wide"
                                    style={{ color: p.GREEN }}>
                                    não lidas
                                </span>
                                <span className="flex-1 h-px" style={{ background: p.GREEN, opacity: 0.4 }} />
                            </div>
                        )}

                        <Bolha
                            mensagem={m}
                            minha={m.autor_id === meuId}
                            lido={m.id > 0 && m.id <= lidaPeloOutroAte}
                            meuId={meuId}
                            onReagir={emoji => reagir(m.id, emoji)}
                            p={p}
                        />
                    </div>
                ))}

                {/* ── "Digitando…" ──
                    Dentro da área que rola, e como último item: assim ele empurra
                    a conversa para cima como se fosse uma bolha chegando, que é
                    o que o WhatsApp faz. Posto por fora (numa faixa fixa), ele
                    faria a conversa inteira pular 20px a cada vez que o outro
                    encostasse no teclado. */}
                {outroDigitando && <Digitando nome={pessoa.nome} p={p} />}

                <div ref={fimRef} />
            </div>

            {/* ── Erro de envio, ou recusa do Ctrl+V ── */}
            {(erro || aviso) && (
                <div className="px-3 py-1.5 text-[11px] flex items-start gap-2"
                    style={{ background: 'rgba(248,81,73,0.12)', color: p.RED }}>
                    <span className="flex-1">{erro ?? aviso}</span>
                    <button
                        onClick={() => { limparErro(); setAviso(null); }}
                        className="shrink-0 opacity-70 hover:opacity-100"
                    >✕</button>
                </div>
            )}

            {/* ── Escrever ──
                O onPaste vive no <form> e não no textarea: assim o Ctrl+V pega
                com o foco em qualquer canto da área de escrever, e continua
                sem alcançar o resto do sistema. */}
            <form onSubmit={submeter} onPaste={colar} className="p-2 shrink-0"
                style={{ borderTop: `1px solid ${p.BORDER}` }}>

                {arquivo && (
                    <div className="flex items-center gap-2 mb-2 p-1.5 rounded-lg text-[11px]"
                        style={{ background: p.HOVER_ROW, color: p.TEXT }}>

                        {previa ? (
                            <img src={previa} alt="" className="w-10 h-10 rounded object-cover shrink-0"
                                style={{ border: `1px solid ${p.BORDER}` }} />
                        ) : (
                            <Icone path="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                className="w-5 h-5 shrink-0 ml-1" />
                        )}

                        <span className="flex-1 min-w-0">
                            <span className="block truncate">{arquivo.name}</span>
                            <span className="block opacity-60">{Math.round(arquivo.size / 1024)} KB</span>
                        </span>

                        <button type="button" title="Tirar o anexo" onClick={tirarAnexo}
                            className="shrink-0 px-1" style={{ color: p.RED }}>✕</button>
                    </div>
                )}

                {/* `relative` aqui: é o berço do seletor de emoji, que se
                    posiciona por cima da conversa (bottom-full) sem esticar a
                    barra nem empurrar as mensagens. */}
                <div className="flex items-end gap-1.5 relative">

                    {emojiAberto && (
                        // Sem tela de carregando: o pedaço tem poucos KB e vem
                        // do mesmo servidor: piscar um "carregando..." de 80ms
                        // chamaria mais atenção do que a espera.
                        <Suspense fallback={null}>
                            <SeletorEmoji
                                onEscolher={inserirEmoji}
                                onFechar={() => setEmojiAberto(false)}
                                p={p}
                            />
                        </Suspense>
                    )}

                    <button type="button" title="Anexar foto ou documento — ou cole um print com Ctrl+V"
                        onClick={() => arquivoRef.current?.click()}
                        className="p-2 rounded-lg transition hover:opacity-70 shrink-0"
                        style={{ color: p.MUTED }}>
                        <Icone path="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                            className="w-5 h-5" />
                    </button>

                    <button type="button" title="Emoji"
                        onClick={() => setEmojiAberto(a => !a)}
                        className="p-2 rounded-lg transition hover:opacity-70 shrink-0"
                        style={{ color: emojiAberto ? p.ACCENT : p.MUTED }}>
                        <Icone path="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
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
                        ref={textoRef}
                        value={texto}
                        onChange={e => {
                            setTexto(e.target.value);

                            /*
                             * Avisa o outro lado que estou escrevendo.
                             *
                             * Sai daqui a CADA tecla, mas o ChatProvider segura:
                             * um aviso a cada 2 segundos, no máximo. E ele não
                             * passa pelo servidor — é whisper, vai do navegador
                             * ao Reverb e do Reverb ao outro navegador.
                             */
                            avisarQueDigito();
                        }}
                        rows={1}
                        maxLength={2000}
                        // Sem texto de fundo com a conversa vazia — o campo fica
                        // limpo. A dica do Ctrl+V continua no tooltip do clipe.
                        placeholder={arquivo ? 'Legenda (opcional)' : ''}
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
                            // A altura é calculada pelo conteúdo (ver o efeito
                            // acima). Nada de maxHeight fixo aqui: era ele que
                            // fazia a barrinha de rolagem aparecer na 4ª linha.
                            overflowY: 'hidden',
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

/**
 * A bolha de "digitando…" — os três pontinhos.
 *
 * Desenhada como uma bolha do OUTRO (à esquerda, mesmo fundo das dele) de
 * propósito: ela ocupa o lugar exato onde a mensagem vai aparecer daqui a
 * pouco, então quando a mensagem chega nada salta na tela — a bolha cinza
 * simplesmente vira a mensagem.
 *
 * O que a alimenta não custa nada ao servidor: o aviso vem por whisper, de
 * navegador a navegador, e o PHP nunca fica sabendo (ver ChatProvider).
 */
function Digitando({ nome, p }: { nome: string; p: Palette }) {
    return (
        <div className="flex justify-start">
            <div
                className="rounded-2xl px-3 py-2.5 flex items-center gap-1"
                style={{ background: p.HOVER_ROW, borderBottomLeftRadius: 4 }}
                // Quem usa leitor de tela não vê pontinho pular. O aria-label diz
                // o que eles significam; o aria-live faz o leitor anunciar quando
                // a bolha aparece, em vez de ficar mudo.
                role="status"
                aria-live="polite"
                aria-label={`${nome.split(' ')[0]} está digitando`}
            >
                {[0, 1, 2].map(i => (
                    <span
                        key={i}
                        className="chat-ponto w-1.5 h-1.5 rounded-full"
                        style={{
                            background: p.MUTED,
                            // O que separa um pontinho do outro é só o atraso.
                            animationDelay: `${i * 180}ms`,
                        }}
                    />
                ))}
            </div>
        </div>
    );
}
