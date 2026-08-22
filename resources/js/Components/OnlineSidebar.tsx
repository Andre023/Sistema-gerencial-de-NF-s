import { useState, useEffect, useMemo } from 'react';
import { useTheme } from '@/Contexts/ThemeContext';
import { Avatar as AvatarTipo } from '@/types';
import { DARK, LIGHT, Palette } from '@/lib/tema';
import Avatar from '@/Components/painel/Avatar';
import Icone from '@/Components/painel/Icone';
import ListaPessoas from '@/Components/chat/ListaPessoas';
import PainelConversa from '@/Components/chat/PainelConversa';
import { useChat } from '@/Components/chat/ChatProvider';

interface UsuarioOnline { id: number; name: string; avatar?: AvatarTipo | null }
interface Props { currentUserId: number }

/**
 * A barra da direita. Três larguras, três funções:
 *
 *   recolhida (w-14)  só os avatares de quem está online, com o balãozinho de
 *                     mensagem por ler. É o estado padrão — ocupa quase nada.
 *   lista     (w-64)  os nomes, a prévia da última mensagem e o não lido.
 *   conversa  (w-80)  a conversa aberta, com foto e nome no topo.
 *
 * Como se anda entre elas:
 *   « »          recolhida ⇄ lista   (e, de dentro da conversa, volta a recolher)
 *   clicar num nome        → conversa
 *   seta de voltar         → lista
 *
 * Só aparece a partir de 1024px (`hidden lg:flex`): abaixo disso a barra
 * comeria a tela da fila de notas, que é o trabalho de verdade.
 */
export default function OnlineSidebar({ currentUserId }: Props) {
    const [expandida, setExpandida] = useState(false);
    const [usuariosOnline, setUsuariosOnline] = useState<UsuarioOnline[]>([]);
    const { isDark } = useTheme();
    const p: Palette = isDark ? DARK : LIGHT;

    const { pessoas, pendentes, naoLidas, aberta, chegada, carregarLista, abrirConversa, fecharConversa } = useChat();

    useEffect(() => {
        window.Echo.join('presenca.sistema')
            .here((users: UsuarioOnline[]) => setUsuariosOnline(users))
            .joining((user: UsuarioOnline) => setUsuariosOnline(prev => [...prev, user]))
            .leaving((user: UsuarioOnline) => setUsuariosOnline(prev => prev.filter(u => u.id !== user.id)))
            .error((error: any) => console.error('Erro no Reverb:', error));

        return () => { window.Echo.leave('presenca.sistema'); };
    }, []);

    const online = useMemo(() => new Set(usuariosOnline.map(u => u.id)), [usuariosOnline]);

    // A lista é buscada só quando a barra abre pela primeira vez — não vale
    // pesar toda navegação com ela.
    useEffect(() => {
        if (expandida && !pessoas) carregarLista();
    }, [expandida, pessoas, carregarLista]);

    /*
     * Mensagem nova abre a barra sozinha — e SÓ isso.
     *
     * Abre a LISTA, nunca a conversa. A diferença não é de estilo: abrir a
     * conversa é o que marca as mensagens como lidas (ConversaController::mostrar
     * chama marcarLida ao entregar a thread). Se a barra abrisse já dentro do
     * chat, o ✓✓ acenderia no aparelho de quem mandou sem ninguém ter lido nada
     * — e o "visualizado" passaria a mentir.
     *
     * Aqui ninguém vai ao servidor buscar mensagem: o efeito de cima carrega a
     * LISTA de pessoas, que é só nome, prévia e contador. Prévia não é leitura.
     *
     * Estando numa conversa aberta, `expandida` já é true e isto não faz nada —
     * ninguém é arrancado do meio de uma conversa porque um terceiro falou.
     */
    useEffect(() => {
        if (chegada) setExpandida(true);
    }, [chegada]);

    const abrir = () => setExpandida(true);

    /** O « » : fecha tudo, inclusive uma conversa aberta. */
    const alternar = () => {
        if (expandida) {
            fecharConversa();
            setExpandida(false);
        } else {
            setExpandida(true);
        }
    };

    /**
     * Quantas mensagens por ler tem esta pessoa (o balãozinho no avatar).
     *
     * Sai dos pendentes e não da lista completa: os pendentes existem desde o
     * carregamento da página, a lista só depois de a barra ser aberta.
     */
    const naoLidasDe = (id: number) => pendentes.find(x => x.id === id)?.nao_lidas ?? 0;

    const emConversa = expandida && aberta !== null;
    const pessoaAberta = pessoas?.find(x => x.id === aberta) ?? null;

    /*
     * A ordem dos ícones na barra recolhida — a regra do WhatsApp:
     *
     *   1. quem tem mensagem por ler, do mais recente para o mais antigo
     *   2. depois o resto de quem está online
     *
     * Quem acabou de falar sobe para o primeiro lugar. Antes ele ficava onde
     * estivesse na lista de presença, ou ia para o FIM se estivesse offline —
     * ou seja, a pessoa mais importante era a mais escondida.
     *
     * Os pendentes vêm junto com a página (props compartilhadas), então o rosto
     * já aparece no lugar certo sem ninguém abrir a barra. Antes dependiam da
     * lista completa, que só era buscada ao expandir — e até lá havia um número
     * aceso sem dizer de quem era.
     */
    const naBarraRecolhida = useMemo(() => {
        const comPendencia = pendentes.map(x => ({
            id: x.id,
            name: x.nome,
            avatar: x.avatar,
            offline: !online.has(x.id),
        }));

        const jaListado = new Set(comPendencia.map(x => x.id));

        const demaisOnline = usuariosOnline
            .filter(u => !jaListado.has(u.id))
            .map(u => ({ id: u.id, name: u.name, avatar: u.avatar ?? null, offline: false }));

        return [...comPendencia, ...demaisOnline];
    }, [usuariosOnline, pendentes, online]);

    const largura = emConversa ? 'w-80' : (expandida ? 'w-64' : 'w-14');

    /*
     * A barra diz ao resto da tela quanto espaço ocupa, numa variável de CSS.
     *
     * Quem precisa disso é o aviso de nota nova (AvisosNaTela): ele mora no
     * canto inferior DIREITO, que é exatamente onde fica o campo de escrever
     * do chat. Sem esta medida o card caía por cima do campo, e o clique que ia
     * para "enviar" abria a nota do aviso — jogando na fila de notas um filtro
     * que ninguém pediu.
     *
     * Vai numa variável, e não numa prop, porque o aviso é irmão da barra (os
     * dois penduram no layout): passar a largura de um ao outro obrigaria a
     * subir esse estado até o AuthenticatedLayout só para descer de novo.
     *
     * Abaixo de 1024px a barra não existe (`hidden lg:flex`) e a largura é zero:
     * ali o canto está livre e o aviso volta a encostar na borda.
     */
    const larguraPx = emConversa ? 320 : (expandida ? 256 : 56);

    useEffect(() => {
        const raiz = document.documentElement;
        const aplicar = () => {
            const visivel = window.matchMedia('(min-width: 1024px)').matches;
            raiz.style.setProperty('--barra-chat', `${visivel ? larguraPx : 0}px`);
        };

        aplicar();

        // A barra some ao estreitar a janela: sem ouvir isso, o aviso ficaria
        // flutuando longe da borda numa tela onde não há barra nenhuma.
        const consulta = window.matchMedia('(min-width: 1024px)');
        consulta.addEventListener('change', aplicar);

        return () => {
            consulta.removeEventListener('change', aplicar);
            raiz.style.removeProperty('--barra-chat');
        };
    }, [larguraPx]);

    return (
        <aside
            className={`hidden lg:flex flex-col sticky top-16 h-[calc(100vh-4rem)] border-l shadow-sm
                        transition-all duration-200 shrink-0 ${largura}`}
            style={{ background: p.SURFACE, borderColor: p.BORDER }}
        >
            {/* ── Barra de controle ── */}
            <button
                onClick={alternar}
                title={expandida ? 'Fechar a barra' : 'Abrir conversas'}
                className="relative flex items-center justify-center h-10 w-full shrink-0 transition"
                style={{ borderBottom: `1px solid ${p.BORDER}`, color: p.MUTED }}
                onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
            >
                <Icone
                    path="M13 5l7 7-7 7M5 5l7 7-7 7"
                    className={`w-4 h-4 transition-transform duration-200 ${expandida ? 'rotate-180' : ''}`}
                />

                {/* Com a barra recolhida, este é o único sinal de mensagem nova */}
                {!expandida && naoLidas > 0 && (
                    <span className="absolute top-1 right-1.5 min-w-[16px] h-4 px-1 rounded-full text-[9px] font-bold
                                     flex items-center justify-center text-white"
                        style={{ background: p.GREEN }}>
                        {naoLidas > 99 ? '99+' : naoLidas}
                    </span>
                )}
            </button>

            {/* ── Conteúdo ── */}
            {emConversa && pessoaAberta ? (
                <PainelConversa
                    pessoa={pessoaAberta}
                    online={online.has(pessoaAberta.id)}
                    meuId={currentUserId}
                    p={p}
                />
            ) : expandida ? (
                <div className="flex-1 min-h-0 overflow-y-auto scrollbar-oculta">
                    <p className="text-[10px] font-semibold uppercase tracking-wider px-3 pt-3 pb-1"
                        style={{ color: p.MUTED }}>
                        Conversas · {online.size} online
                    </p>
                    <ListaPessoas online={online} p={p} />
                </div>
            ) : (
                // ── Recolhida: só os avatares ──
                <div className="flex-1 overflow-y-auto overflow-x-hidden scrollbar-oculta py-3 px-2 space-y-2">
                    {naBarraRecolhida.map(u => {
                        const isMe = u.id === currentUserId;
                        const pendentes = naoLidasDe(u.id);

                        return (
                            <button
                                key={u.id}
                                onClick={() => {
                                    if (isMe) return;
                                    setExpandida(true);
                                    abrirConversa(u.id);
                                }}
                                title={isMe ? 'Você' : `Conversar com ${u.name}`}
                                className="relative block"
                                style={{ cursor: isMe ? 'default' : 'pointer' }}
                            >
                                <Avatar
                                    user={{ name: u.name, avatar: u.avatar }}
                                    size={32}
                                    ring={p.SURFACE}
                                    title={isMe ? 'Você' : u.name}
                                />

                                {!u.offline && (
                                    <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full"
                                        style={{ background: p.GREEN, border: `2px solid ${p.SURFACE}` }} />
                                )}

                                {pendentes > 0 && (
                                    <span className="absolute -top-1 -right-1 min-w-[15px] h-[15px] px-1 rounded-full
                                                     text-[9px] font-bold flex items-center justify-center text-white"
                                        style={{ background: p.GREEN, border: `1.5px solid ${p.SURFACE}` }}>
                                        {pendentes > 9 ? '9+' : pendentes}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            )}
        </aside>
    );
}
