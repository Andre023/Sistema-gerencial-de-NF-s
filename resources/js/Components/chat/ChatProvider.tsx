import {
    createContext, PropsWithChildren, useCallback, useContext, useEffect, useRef, useState,
} from 'react';
import { usePage } from '@inertiajs/react';
import { Avatar, Mensagem, PageProps, PendenteChat, PessoaChat, Reacao } from '@/types';
import { biparMensagem } from '@/lib/som';
import { otimizarParaEnvio } from '@/lib/imagem';

/**
 * O cérebro do chat — montado UMA vez pelo layout, junto do sino.
 *
 * Por que aqui e não dentro da barra lateral: o balãozinho de não lidas precisa
 * aparecer com a barra recolhida, e o bipe precisa tocar mesmo com a barra
 * fechada. Se o estado morasse no componente da barra, ele se perderia a cada
 * abre-e-fecha e as mensagens chegariam mudas.
 *
 * ── Tempo real ────────────────────────────────────────────────────────────
 * Usa o canal `usuario.{id}` que o sino JÁ assina — nada de canal por conversa.
 * Com 26 pessoas seriam centenas de assinaturas paradas no Reverb, numa VM de
 * 1 GB, para entregar o que um canal por pessoa já entrega.
 *
 * Por isso a limpeza usa `stopListening` e NÃO `leave`: sair do canal aqui
 * derrubaria o sino junto, que é vizinho de porta.
 */

/** O que o Reverb entrega quando alguém manda mensagem. */
interface EventoMensagem {
    conversa_id: number;
    mensagem: Mensagem;
    autor: { id: number | null; name: string | null; avatar: Avatar | null };
}

interface ContextoChat {
    /** null = a lista ainda não foi buscada (a barra nunca foi aberta) */
    pessoas: PessoaChat[] | null;
    /**
     * Quem está devendo resposta, do mais recente para o mais antigo. Chega
     * junto com a página, então existe mesmo antes de alguém abrir a barra —
     * é o que põe o rosto de quem falou no topo dos ícones.
     */
    pendentes: PendenteChat[];
    naoLidas: number;
    carregandoLista: boolean;

    /** id da pessoa com quem a conversa está aberta */
    aberta: number | null;
    mensagens: Mensagem[];
    carregandoConversa: boolean;
    temAntigas: boolean;
    /** até que id o outro leu — acende o ✓✓ nas minhas bolhas */
    lidaPeloOutroAte: number;
    /**
     * Até onde EU tinha lido quando abri esta conversa. Congelado na abertura:
     * é o que marca onde parou a leitura, e não pode andar enquanto a conversa
     * está na tela — senão a divisória "não lidas" fugiria para baixo a cada
     * mensagem que chega.
     */
    leituraAoAbrir: number;
    enviando: boolean;
    erro: string | null;
    /** O outro lado está escrevendo agora (chega por whisper, ver abaixo). */
    outroDigitando: boolean;
    /**
     * Quem acabou de mandar mensagem, por poucos segundos.
     *
     * Serve a duas coisas de uma vez: abrir a barra lateral sozinha e acender o
     * realce no nome de quem falou. Some depois de REALCE — daí em diante o
     * nome continua verde (isso é estado, não aviso), mas parado.
     *
     * `n` sobe a cada chegada porque o valor precisa MUDAR sempre: duas
     * mensagens seguidas da mesma pessoa dariam o mesmo `{id}`, o React não
     * veria diferença e o realce não reacenderia na segunda.
     */
    chegada: { id: number; n: number } | null;

    carregarLista: () => void;
    abrirConversa: (pessoaId: number) => void;
    fecharConversa: () => void;
    enviar: (texto: string, arquivo?: File | null) => Promise<void>;
    carregarAntigas: () => void;
    limparErro: () => void;
    /** Põe, troca ou tira o meu emoji nesta mensagem (o servidor decide qual). */
    reagir: (mensagemId: number, emoji: string) => void;
    /** Chamado a cada tecla. O aperto de mão com o outro lado é feito aqui. */
    avisarQueDigito: () => void;
}

/*
 * ── Os tempos do "digitando…" ─────────────────────────────────────────────
 *
 * De quanto em quanto tempo, no máximo, um aviso sai daqui enquanto a pessoa
 * escreve. Sem isto seria um evento POR TECLA — e mesmo sendo whisper (que não
 * acorda o PHP), mandar 300 quadros por minuto para dizer a mesma coisa é
 * desperdício de rede no wi-fi do galpão.
 */
const INTERVALO_AVISO = 2000;

/** Silêncio no teclado que já conta como "parou de escrever". */
const PARADA = 2500;

/**
 * Quanto tempo o nome de quem acabou de falar fica em realce.
 *
 * Curto de propósito. O realce é o "olha aqui" do instante em que a mensagem
 * cai; o verde que fica depois já diz que há coisa por ler. Nome piscando sem
 * parar, numa tela que a equipe encara o dia inteiro e com 26 pessoas podendo
 * falar, deixa de chamar atenção e passa a atrapalhar.
 *
 * Combina com a animação em app.css (.chat-realce): 1s por ciclo, 3 ciclos —
 * ela termina antes de o realce ser apagado aqui, e não fica cortada no meio.
 */
const REALCE = 4000;

/**
 * Prazo de validade do "digitando" que chegou.
 *
 * O aviso de parada pode se perder — a aba fecha, o wi-fi cai, a pessoa some no
 * meio da frase. Sem este prazo, o "digitando…" ficaria aceso para sempre, e um
 * indicador que mente é pior do que não ter indicador.
 */
const SUMICO = 6000;

const Contexto = createContext<ContextoChat | null>(null);

export function useChat(): ContextoChat {
    const ctx = useContext(Contexto);
    if (!ctx) throw new Error('useChat precisa estar dentro de <ChatProvider>');
    return ctx;
}

export default function ChatProvider({ userId, children }: PropsWithChildren<{ userId: number }>) {
    const { conversasPendentes } = usePage<PageProps>().props;

    const [pessoas, setPessoas]     = useState<PessoaChat[] | null>(null);
    const [pendentes, setPendentes] = useState<PendenteChat[]>(conversasPendentes ?? []);
    const [carregandoLista, setCarregandoLista] = useState(false);

    /*
     * O total é DERIVADO dos pendentes, não guardado à parte.
     *
     * Antes eram dois estados (um número e uma lista) que precisavam concordar,
     * e toda ação tinha de lembrar de mexer nos dois. Um contador que discorda
     * dos rostos ao lado é pior que não ter contador.
     */
    const naoLidas = pendentes.reduce((soma, x) => soma + x.nao_lidas, 0);

    const [aberta, setAberta]         = useState<number | null>(null);
    const [conversaId, setConversaId] = useState<number | null>(null);
    const [mensagens, setMensagens]   = useState<Mensagem[]>([]);
    const [carregandoConversa, setCarregandoConversa] = useState(false);
    const [temAntigas, setTemAntigas] = useState(false);
    const [lidaPeloOutroAte, setLidaPeloOutroAte] = useState(0);
    const [leituraAoAbrir, setLeituraAoAbrir] = useState(0);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);
    const [outroDigitando, setOutroDigitando] = useState(false);
    const [chegada, setChegada] = useState<{ id: number; n: number } | null>(null);

    /** Só para o `n` do `chegada` — ver o comentário na interface acima. */
    const chegadasRef = useRef(0);

    /**
     * O canal da conversa ABERTA — o único lugar do chat onde os dois lados
     * estão no mesmo canal, e por isso o único por onde o whisper funciona.
     *
     * Fica numa ref porque quem o usa é o `avisarQueDigito`, chamado a cada
     * tecla: um estado faria o componente inteiro redesenhar a cada letra.
     */
    const canalConversaRef = useRef<any>(null);

    /** Quando saiu o último aviso — é o que segura o INTERVALO_AVISO. */
    const ultimoAvisoRef = useRef(0);

    /** Apaga o "digitando" do outro se o aviso de parada dele se perder. */
    const sumicoRef = useRef<number | null>(null);

    /** Manda o "parou" quando as minhas teclas cessam. */
    const paradaRef = useRef<number | null>(null);

    /*
     * O handler do Echo é registrado uma vez só, mas precisa saber qual conversa
     * está aberta AGORA. Sem a ref ele enxergaria o valor do primeiro render
     * para sempre — e mensagem nova nunca entraria na conversa aberta.
     */
    const conversaIdRef = useRef<number | null>(null);
    conversaIdRef.current = conversaId;

    // Navegação normal do Inertia traz os pendentes recalculados pelo servidor —
    // é o que reconcilia a conta se algum evento se perdeu no caminho.
    useEffect(() => {
        if (conversasPendentes) setPendentes(conversasPendentes);
    }, [conversasPendentes]);

    const limparErro = useCallback(() => setErro(null), []);

    // ── "Digitando…" ──────────────────────────────────────────────────────────
    //
    // Isto NÃO passa pelo servidor. `whisper` é evento de CLIENTE: sai do
    // navegador, o Reverb repassa para os outros assinantes do canal e acabou.
    // Nem PHP nem MySQL acordam — que é justamente o que torna o recurso viável
    // numa VM de 1 GB com 6 workers de PHP-FPM.
    //
    // Se isto fosse um POST por tecla, cada pessoa escrevendo disputaria os
    // mesmos 6 workers que servem as páginas de verdade.

    /** Corta o aviso pela raiz: some o "parou" pendente e libera o intervalo. */
    const pararDeAvisar = useCallback(() => {
        if (paradaRef.current) {
            clearTimeout(paradaRef.current);
            paradaRef.current = null;
        }

        ultimoAvisoRef.current = 0;

        canalConversaRef.current?.whisper('digitando', { digitando: false });
    }, []);

    const avisarQueDigito = useCallback(() => {
        const canal = canalConversaRef.current;

        // Conversa que ainda não nasceu (nenhuma mensagem trocada) não tem
        // canal — e não tem para quem avisar. Escrever nela é normal.
        if (!canal) return;

        const agora = Date.now();

        if (agora - ultimoAvisoRef.current > INTERVALO_AVISO) {
            ultimoAvisoRef.current = agora;
            canal.whisper('digitando', { digitando: true });
        }

        // Cada tecla adia o "parou". Ele só dispara quando as teclas cessam de
        // verdade por PARADA milissegundos.
        if (paradaRef.current) clearTimeout(paradaRef.current);

        paradaRef.current = window.setTimeout(() => {
            paradaRef.current = null;
            ultimoAvisoRef.current = 0;
            canal.whisper('digitando', { digitando: false });
        }, PARADA);
    }, []);

    // ── Lista de pessoas ──────────────────────────────────────────────────────

    const carregarLista = useCallback(async () => {
        setCarregandoLista(true);
        try {
            const { data } = await window.axios.get(route('conversas.index'));
            setPessoas(data.pessoas);

            // A lista completa é a verdade mais nova: os pendentes se refazem a
            // partir dela, em vez de ficarem contando por conta própria.
            setPendentes(
                (data.pessoas as PessoaChat[])
                    .filter(x => x.nao_lidas > 0)
                    .map(x => ({
                        id: x.id, nome: x.nome, avatar: x.avatar,
                        nao_lidas: x.nao_lidas, em: x.ultima?.em ?? '',
                    }))
                    .sort((a, b) => b.em.localeCompare(a.em)),
            );
        } catch {
            setErro('Não foi possível carregar a lista.');
        } finally {
            setCarregandoLista(false);
        }
    }, []);

    // ── Abrir / fechar conversa ───────────────────────────────────────────────

    const abrirConversa = useCallback(async (pessoaId: number) => {
        setAberta(pessoaId);
        setMensagens([]);
        setErro(null);
        setCarregandoConversa(true);

        try {
            const { data } = await window.axios.get(route('conversas.mostrar', pessoaId));

            setConversaId(data.conversa_id);
            setMensagens(data.mensagens);
            setTemAntigas(data.tem_antigas);
            setLidaPeloOutroAte(data.lida_pelo_outro_ate);
            setLeituraAoAbrir(data.minha_leitura_ate ?? 0);

            // O servidor marcou como lida ao entregar a conversa; a tela
            // acompanha, senão o rosto e o balãozinho ficariam acesos até a
            // próxima navegação de página.
            setPendentes(atual => atual.filter(x => x.id !== pessoaId));

            setPessoas(atual => atual?.map(p => p.id === pessoaId
                ? { ...p, nao_lidas: 0, conversa_id: data.conversa_id }
                : p) ?? atual);
        } catch {
            setErro('Não foi possível abrir a conversa.');
        } finally {
            setCarregandoConversa(false);
        }
    }, []);

    const fecharConversa = useCallback(() => {
        setAberta(null);
        setConversaId(null);
        setMensagens([]);
        setErro(null);
    }, []);

    const carregarAntigas = useCallback(async () => {
        if (!aberta || !mensagens.length) return;

        try {
            const { data } = await window.axios.get(route('conversas.mostrar', aberta), {
                params: { antes: mensagens[0].id },
            });

            setMensagens(atual => [...data.mensagens, ...atual]);
            setTemAntigas(data.tem_antigas);
        } catch {
            setErro('Não foi possível carregar as mensagens anteriores.');
        }
    }, [aberta, mensagens]);

    // ── Enviar ────────────────────────────────────────────────────────────────

    const enviar = useCallback(async (texto: string, arquivo?: File | null) => {
        if (!aberta) return;

        const limpo = texto.trim();
        if (!limpo && !arquivo) return;

        setEnviando(true);
        setErro(null);

        /*
         * Bolha otimista: aparece na hora, com id NEGATIVO para não colidir com
         * id nenhum do banco. É o que faz o envio parecer instantâneo mesmo com
         * o wi-fi ruim do galpão — e o id negativo é como a substituição acha
         * a bolha certa depois.
         */
        const provisorio = -Date.now();

        /*
         * Mandou: o "digitando…" do outro lado tem de apagar AGORA.
         *
         * Sem isto ele ficaria aceso até o PARADA vencer — e a cena é ruim: a
         * mensagem já chegou e a tela ainda diz que a pessoa está escrevendo.
         */
        pararDeAvisar();

        setMensagens(atual => [...atual, {
            id: provisorio,
            texto: limpo || null,
            autor_id: userId,
            autor: null,
            created_at: new Date().toISOString(),
            reacoes: [],
            anexo: arquivo ? {
                nome: arquivo.name,
                mime: arquivo.type,
                tamanho: arquivo.size,
                imagem: arquivo.type.startsWith('image/'),
                no_servidor: true,
                removido_em: null,
            } : null,
            pendente: true,
        }]);

        try {
            const corpo = new FormData();
            if (limpo) corpo.append('texto', limpo);

            if (arquivo) {
                // Reduz e converte para WebP AQUI, no aparelho de quem envia: a
                // VM tem 1 GB e não abre foto de 12 MP sem risco de derrubar o
                // MySQL junto (ver lib/imagem).
                const { arquivo: pronto } = await otimizarParaEnvio(arquivo);
                corpo.append('arquivo', pronto);
            }

            const { data } = await window.axios.post(route('conversas.enviar', aberta), corpo, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            setConversaId(data.conversa_id);

            /*
             * Troca a bolha provisória pela real. O evento do Echo pode chegar
             * ANTES desta linha (o servidor transmite antes de responder), então
             * a substituição confere se a real já não entrou pela porta dos
             * fundos — senão a mensagem apareceria duas vezes.
             */
            setMensagens(atual => {
                const semProvisoria = atual.filter(m => m.id !== provisorio);

                return semProvisoria.some(m => m.id === data.mensagem.id)
                    ? semProvisoria
                    : [...semProvisoria, data.mensagem];
            });

            setPessoas(atual => atual?.map(p => p.id === aberta ? {
                ...p,
                conversa_id: data.conversa_id,
                ultima: { previa: previa(data.mensagem), em: data.mensagem.created_at, minha: true },
            } : p) ?? atual);
        } catch (e: any) {
            const erros = e?.response?.data?.errors;

            setErro(
                erros?.arquivo?.[0]
                ?? erros?.texto?.[0]
                ?? e?.response?.data?.message
                ?? 'Não foi possível enviar.',
            );

            // A bolha fica na tela marcada como falha, em vez de sumir levando
            // junto o que a pessoa escreveu.
            setMensagens(atual => atual.map(m => m.id === provisorio
                ? { ...m, pendente: false, falhou: true }
                : m));
        } finally {
            setEnviando(false);
        }
    }, [aberta, userId, pararDeAvisar]);

    // ── Reagir ────────────────────────────────────────────────────────────────

    /**
     * Põe, troca ou tira o meu emoji nesta mensagem.
     *
     * Quem decide QUAL das três coisas acontece é o servidor — aqui só
     * adivinhamos o resultado para a tela responder na hora, e a resposta
     * corrige o palpite. É a mesma ideia da bolha otimista do envio.
     *
     * O palpite acerta em cheio no caso normal (uma pessoa clicando na própria
     * tela). Ele só erra se duas pessoas reagirem no mesmo instante — e aí a
     * resposta, que traz a lista inteira, põe tudo no lugar.
     */
    const reagir = useCallback(async (mensagemId: number, emoji: string) => {
        let anterior: Reacao[] | null = null;

        setMensagens(atual => atual.map(m => {
            if (m.id !== mensagemId) return m;

            const minhas = m.reacoes ?? [];

            // Guardado para desfazer se o servidor recusar
            anterior = minhas;

            const jaEra  = minhas.some(r => r.user_id === userId && r.emoji === emoji);
            const outras = minhas.filter(r => r.user_id !== userId);

            return {
                ...m,
                // Clicou no que já estava: tira. Qualquer outro caso: fica o novo
                // (o filter acima já removeu o meu anterior, se havia).
                reacoes: jaEra ? outras : [...outras, { emoji, user_id: userId }],
            };
        }));

        try {
            const { data } = await window.axios.post(
                route('conversas.mensagens.reagir', mensagemId),
                { emoji },
            );

            setMensagens(atual => atual.map(m => m.id === mensagemId
                ? { ...m, reacoes: data.reacoes }
                : m));
        } catch {
            // Devolve a fileira ao que era: melhor não ter reagido do que a tela
            // mostrar um emoji que o servidor não guardou.
            if (anterior !== null) {
                setMensagens(atual => atual.map(m => m.id === mensagemId
                    ? { ...m, reacoes: anterior! }
                    : m));
            }

            setErro('Não foi possível reagir.');
        }
    }, [userId]);

    // ── Tempo real ────────────────────────────────────────────────────────────

    useEffect(() => {
        const canal = window.Echo.private(`usuario.${userId}`);

        const chegou = (e: EventoMensagem) => {
            const minha    = e.mensagem.autor_id === userId;
            const naAberta = conversaIdRef.current !== null && e.conversa_id === conversaIdRef.current;

            // Com a conversa aberta, a mensagem entra na thread e já conta como
            // lida — a pessoa está olhando para ela.
            if (naAberta) {
                setMensagens(atual => atual.some(m => m.id === e.mensagem.id)
                    ? atual
                    : [...atual, e.mensagem]);

                if (!minha) {
                    window.axios
                        .post(route('conversas.lida', e.conversa_id), { ate: e.mensagem.id })
                        .catch(() => {});
                }
            }

            /*
             * Qual linha da lista atualizar.
             *
             * Pela CONVERSA, não pelo autor: quando a mensagem é minha (mandei
             * de outra aba), o autor sou eu e não diz nada sobre com quem eu
             * estava falando. O autor só serve de reserva para conversa recém
             * nascida, que a lista local ainda não conhece pelo id.
             */
            setPessoas(atual => {
                if (!atual) return atual;

                const alvo = atual.find(p => p.conversa_id === e.conversa_id)
                    ?? (minha ? undefined : atual.find(p => p.id === e.autor.id));

                if (!alvo) return atual;

                return atual.map(p => p.id !== alvo.id ? p : {
                    ...p,
                    conversa_id: e.conversa_id,
                    ultima: { previa: previa(e.mensagem), em: e.mensagem.created_at, minha },
                    nao_lidas: (!minha && !naAberta) ? p.nao_lidas + 1 : p.nao_lidas,
                });
            });

            if (minha || naAberta) return;

            /*
             * O rosto de quem falou vai para o TOPO — é o pedido do WhatsApp:
             * a conversa mais recente é a primeira, sempre.
             *
             * Quem já estava na fila sai da posição antiga e volta na frente com
             * o contador somado; quem não estava, entra na frente.
             */
            setPendentes(atual => {
                const antes = atual.find(x => x.id === e.autor.id);

                const novo: PendenteChat = {
                    id: e.autor.id!,
                    nome: e.autor.name ?? '',
                    avatar: e.autor.avatar,
                    nao_lidas: (antes?.nao_lidas ?? 0) + 1,
                    em: e.mensagem.created_at,
                };

                return [novo, ...atual.filter(x => x.id !== e.autor.id)];
            });

            /*
             * O sinal que abre a barra lateral e acende o nome de quem falou.
             *
             * Fica DEPOIS do `if (minha || naAberta) return` lá em cima, e é de
             * propósito: mensagem minha (mandada de outra aba) não tem por que
             * abrir barra nenhuma, e a que chega na conversa já aberta a pessoa
             * está lendo neste instante.
             *
             * Conta removida no meio do caminho não tem id para realçar — aí
             * fica só o aviso sonoro, como antes.
             */
            if (e.autor.id !== null) {
                chegadasRef.current += 1;
                setChegada({ id: e.autor.id, n: chegadasRef.current });
            }

            avisar(e.autor.name ?? 'Mensagem nova', e.mensagem);
        };

        const mudou = (e: { conversa_id: number; o_que: string; mensagem_id: number }) => {
            if (e.o_que === 'lida' && e.conversa_id === conversaIdRef.current) {
                setLidaPeloOutroAte(n => Math.max(n, e.mensagem_id));
            }
        };

        /*
         * Reação de alguém numa mensagem.
         *
         * O evento traz a lista INTEIRA daquela mensagem, não "fulano pôs 👍" —
         * então aqui é substituição, não soma. Evento perdido no wi-fi do galpão
         * custa um piscar de olhos e o próximo conserta; com soma, o contador
         * ficaria errado para sempre.
         *
         * Só interessa se a conversa está aberta: reação de propósito não acende
         * balãozinho nem toca o bipe. Quem não está olhando não precisa saber.
         */
        const reagiu = (e: { conversa_id: number; mensagem_id: number; reacoes: Reacao[] }) => {
            if (e.conversa_id !== conversaIdRef.current) return;

            setMensagens(atual => atual.map(m => m.id === e.mensagem_id
                ? { ...m, reacoes: e.reacoes }
                : m));
        };

        canal.listen('.MensagemEnviada', chegou);
        canal.listen('.ConversaAtualizada', mudou);
        canal.listen('.ReacaoAtualizada', reagiu);

        return () => {
            // stopListening e NÃO leave: o sino mora neste mesmo canal
            canal.stopListening('.MensagemEnviada', chegou);
            canal.stopListening('.ConversaAtualizada', mudou);
            canal.stopListening('.ReacaoAtualizada', reagiu);
        };
    }, [userId]);

    /*
     * O realce se apaga sozinho.
     *
     * O timer é refeito a cada chegada (o `chegada` mudou, o efeito rodou de
     * novo, o anterior foi cancelado na limpeza) — então duas mensagens
     * seguidas dão 4 segundos contados da SEGUNDA, e não um apagão no meio do
     * realce da primeira.
     */
    useEffect(() => {
        if (!chegada) return;

        const id = window.setTimeout(() => setChegada(null), REALCE);

        return () => clearTimeout(id);
    }, [chegada]);

    /*
     * ── O canal da conversa aberta ────────────────────────────────────────────
     *
     * Existe SÓ enquanto a conversa está na tela, e serve SÓ para o "digitando".
     *
     * É aqui que mora a diferença entre este recurso custar zero e custar caro.
     * Nenhum evento do servidor passa por este canal: mensagem, leitura e reação
     * continuam indo pelo `usuario.{id}` de sempre. O que trafega aqui é whisper
     * — o Reverb repassa de um navegador ao outro sem tocar em PHP nem MySQL.
     *
     * O canal precisou existir porque `usuario.{id}` tem UM assinante (o dono):
     * um whisper lá não chegaria a ninguém. Aqui os dois lados se encontram.
     *
     * Assinar por conversa ABERTA é diferente de assinar todas as conversas: são
     * no máximo 26 assinaturas (uma por pessoa), e não as centenas paradas que a
     * decisão original do chat evitou.
     */
    useEffect(() => {
        setOutroDigitando(false);

        // Conversa que ainda não nasceu não tem canal — nem teria o que ouvir.
        if (conversaId === null) return;

        const nome  = `conversa.${conversaId}`;
        const canal = window.Echo.private(nome);

        canalConversaRef.current = canal;

        canal.listenForWhisper('digitando', (e: { digitando: boolean }) => {
            setOutroDigitando(e.digitando);

            if (sumicoRef.current) clearTimeout(sumicoRef.current);

            // O aceso ganha prazo de validade; o apagado não precisa de nenhum.
            if (e.digitando) {
                sumicoRef.current = window.setTimeout(() => setOutroDigitando(false), SUMICO);
            }
        });

        return () => {
            /*
             * Aqui `leave` é o certo — ao contrário do canal do sino, que é
             * vizinho de porta e só admite `stopListening`. Este canal é desta
             * conversa e de mais ninguém: sair dele é o ponto do desenho.
             * Sem o leave, fechar e abrir conversas iria empilhando assinaturas
             * no Reverb até a aba ser fechada.
             */
            window.Echo.leave(nome);

            canalConversaRef.current = null;

            if (sumicoRef.current) clearTimeout(sumicoRef.current);
            if (paradaRef.current) clearTimeout(paradaRef.current);

            ultimoAvisoRef.current = 0;
            setOutroDigitando(false);
        };
    }, [conversaId]);

    return (
        <Contexto.Provider value={{
            pessoas, pendentes, naoLidas, carregandoLista,
            aberta, mensagens, carregandoConversa, temAntigas, lidaPeloOutroAte, leituraAoAbrir, enviando, erro,
            outroDigitando, chegada,
            carregarLista, abrirConversa, fecharConversa, enviar, carregarAntigas, limparErro,
            reagir, avisarQueDigito,
        }}>
            {children}
        </Contexto.Provider>
    );
}

// ─── Auxiliares ───────────────────────────────────────────────────────────────

/** O que a lista mostra embaixo do nome (espelha Conversas::previa no servidor). */
function previa(m: Mensagem): string {
    if (m.texto) return m.texto.slice(0, 60);
    if (m.anexo) return m.anexo.imagem ? 'Foto' : m.anexo.nome;

    return '';
}

/**
 * Aviso de mensagem nova. Mesma regra do sino, e pelo mesmo motivo: com a aba
 * na frente basta o som (o balãozinho já aparece na barra); com a aba escondida,
 * só o sistema operacional alcança a pessoa.
 *
 * A permissão é a mesma que o NotificacoesProvider pede ao entrar — aqui não
 * pedimos de novo, para o chat não gerar um segundo pop-up do navegador.
 */
function avisar(nome: string, mensagem: Mensagem): void {
    if (!document.hidden) {
        biparMensagem();
        return;
    }

    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;

    try {
        const aviso = new Notification(nome, {
            body: mensagem.texto || (mensagem.anexo?.imagem ? 'Enviou uma foto' : 'Enviou um arquivo'),
            tag: `chat-${mensagem.id}`,
            icon: '/favicon.ico',
            requireInteraction: false,
        });

        aviso.onclick = () => { window.focus(); aviso.close(); };

        setTimeout(() => aviso.close(), 5000);
    } catch {
        // aviso do SO é enfeite; a mensagem já está na barra
    }
}
