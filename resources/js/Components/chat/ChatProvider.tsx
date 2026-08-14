import {
    createContext, PropsWithChildren, useCallback, useContext, useEffect, useRef, useState,
} from 'react';
import { usePage } from '@inertiajs/react';
import { Avatar, Mensagem, PageProps, PessoaChat } from '@/types';
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
    naoLidas: number;
    carregandoLista: boolean;

    /** id da pessoa com quem a conversa está aberta */
    aberta: number | null;
    mensagens: Mensagem[];
    carregandoConversa: boolean;
    temAntigas: boolean;
    /** até que id o outro leu — acende o ✓✓ nas minhas bolhas */
    lidaPeloOutroAte: number;
    enviando: boolean;
    erro: string | null;

    carregarLista: () => void;
    abrirConversa: (pessoaId: number) => void;
    fecharConversa: () => void;
    enviar: (texto: string, arquivo?: File | null) => Promise<void>;
    carregarAntigas: () => void;
    limparErro: () => void;
}

const Contexto = createContext<ContextoChat | null>(null);

export function useChat(): ContextoChat {
    const ctx = useContext(Contexto);
    if (!ctx) throw new Error('useChat precisa estar dentro de <ChatProvider>');
    return ctx;
}

export default function ChatProvider({ userId, children }: PropsWithChildren<{ userId: number }>) {
    const { conversasNaoLidas } = usePage<PageProps>().props;

    const [pessoas, setPessoas]   = useState<PessoaChat[] | null>(null);
    const [naoLidas, setNaoLidas] = useState<number>(conversasNaoLidas ?? 0);
    const [carregandoLista, setCarregandoLista] = useState(false);

    const [aberta, setAberta]         = useState<number | null>(null);
    const [conversaId, setConversaId] = useState<number | null>(null);
    const [mensagens, setMensagens]   = useState<Mensagem[]>([]);
    const [carregandoConversa, setCarregandoConversa] = useState(false);
    const [temAntigas, setTemAntigas] = useState(false);
    const [lidaPeloOutroAte, setLidaPeloOutroAte] = useState(0);
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);

    /*
     * O handler do Echo é registrado uma vez só, mas precisa saber qual conversa
     * está aberta AGORA. Sem a ref ele enxergaria o valor do primeiro render
     * para sempre — e mensagem nova nunca entraria na conversa aberta.
     */
    const conversaIdRef = useRef<number | null>(null);
    conversaIdRef.current = conversaId;

    // Navegação normal do Inertia traz o total recalculado pelo servidor — é o
    // que reconcilia a conta se algum evento se perdeu no caminho.
    useEffect(() => {
        if (typeof conversasNaoLidas === 'number') setNaoLidas(conversasNaoLidas);
    }, [conversasNaoLidas]);

    const limparErro = useCallback(() => setErro(null), []);

    // ── Lista de pessoas ──────────────────────────────────────────────────────

    const carregarLista = useCallback(async () => {
        setCarregandoLista(true);
        try {
            const { data } = await window.axios.get(route('conversas.index'));
            setPessoas(data.pessoas);
            setNaoLidas(data.nao_lidas);
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

            // O servidor marcou como lida ao entregar a conversa; a conta local
            // acompanha, senão o balãozinho ficaria aceso até a próxima
            // navegação de página.
            setPessoas(atual => {
                if (!atual) return atual;

                const lidas = atual.find(p => p.id === pessoaId)?.nao_lidas ?? 0;
                if (lidas > 0) setNaoLidas(n => Math.max(0, n - lidas));

                return atual.map(p => p.id === pessoaId
                    ? { ...p, nao_lidas: 0, conversa_id: data.conversa_id }
                    : p);
            });
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

        setMensagens(atual => [...atual, {
            id: provisorio,
            texto: limpo || null,
            autor_id: userId,
            autor: null,
            created_at: new Date().toISOString(),
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
    }, [aberta, userId]);

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

            setNaoLidas(n => n + 1);
            avisar(e.autor.name ?? 'Mensagem nova', e.mensagem);
        };

        const mudou = (e: { conversa_id: number; o_que: string; mensagem_id: number }) => {
            if (e.o_que === 'lida' && e.conversa_id === conversaIdRef.current) {
                setLidaPeloOutroAte(n => Math.max(n, e.mensagem_id));
            }
        };

        canal.listen('.MensagemEnviada', chegou);
        canal.listen('.ConversaAtualizada', mudou);

        return () => {
            // stopListening e NÃO leave: o sino mora neste mesmo canal
            canal.stopListening('.MensagemEnviada', chegou);
            canal.stopListening('.ConversaAtualizada', mudou);
        };
    }, [userId]);

    return (
        <Contexto.Provider value={{
            pessoas, naoLidas, carregandoLista,
            aberta, mensagens, carregandoConversa, temAntigas, lidaPeloOutroAte, enviando, erro,
            carregarLista, abrirConversa, fecharConversa, enviar, carregarAntigas, limparErro,
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
