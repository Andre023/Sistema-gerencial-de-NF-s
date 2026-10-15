import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Secoes, { Cartao } from '@/Components/configuracoes/Secoes';
import Avatar from '@/Components/painel/Avatar';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT } from '@/lib/tema';
import { Avatar as AvatarTipo } from '@/types';

interface Publicacao {
    texto: string;
    em: string;
    pessoas: number;
}

interface Props {
    hipermon: { id: number; name: string; avatar: AvatarTipo } | null;
    pessoas: number;
    historico: Publicacao[];
    textoMax: number;
    diasDeVida: number;
}

const quando = (iso: string) =>
    new Date(iso.replace(' ', 'T')).toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });

/**
 * Configurações › Novidades.
 *
 * O admin escreve e o Hipermon manda, no chat, para cada conta do sistema
 * (App\Services\Novidades). A prévia mostra a mensagem como ela vai chegar —
 * com o robô e o nome dele — para quem escreve ver antes o que todo mundo vai
 * ver depois. Mensagem enviada não volta atrás, daí a confirmação.
 */
export default function Novidades({ hipermon, pessoas, historico, textoMax, diasDeVida }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const [texto, setTexto] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);

    const limpo = texto.trim();

    const enviar = () => {
        if (!limpo || !hipermon) return;
        if (!confirm(`Enviar esta novidade para ${pessoas} pessoa${pessoas === 1 ? '' : 's'}? Depois de enviada ela não pode ser apagada.`)) return;

        setEnviando(true);
        setErro(null);
        router.post(route('configuracoes.novidades.publicar'), { texto: limpo }, {
            preserveScroll: true,
            onSuccess: () => setTexto(''),
            onError: e => setErro(e.texto ?? 'Não foi possível enviar.'),
            onFinish: () => setEnviando(false),
        });
    };

    return (
        <Secoes atual="novidades">
            <Head title="Configurações — Novidades" />

            <div className="space-y-5">
                <Cartao
                    titulo="Contar uma novidade"
                    descricao={`O Hipermon manda a mensagem no chat para todas as ${pessoas} contas do sistema. Ela chega como qualquer conversa — com o balãozinho de não lida — e ninguém consegue responder a ele.`}
                    p={p}
                >
                    {!hipermon ? (
                        <p className="text-sm rounded-lg px-3 py-2"
                            style={{ background: p.AMBER + '14', color: p.AMBER, border: `1px solid ${p.AMBER}33` }}>
                            A conta do Hipermon ainda não existe neste servidor — ela é criada pela migration
                            <code className="mx-1">create_hipermon</code>no próximo deploy.
                        </p>
                    ) : (
                        <>
                            <textarea
                                value={texto}
                                onChange={e => setTexto(e.target.value.slice(0, textoMax))}
                                rows={8}
                                spellCheck
                                placeholder={'Ex.: Novidade no sistema! 🎉\n\n• Agora dá para fixar o cabeçalho da fila com o alfinete 📌\n• Clique no nome da coluna para ordenar a tabela'}
                                className="block w-full rounded-lg text-sm px-3 py-2.5 outline-none leading-relaxed resize-y"
                                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${erro ? p.RED : p.INPUT_BORDER}` }}
                            />

                            {erro && <p className="text-xs mt-1.5" style={{ color: p.RED }}>{erro}</p>}

                            {/* Prévia: como a mensagem chega no chat */}
                            {limpo && (
                                <div className="mt-4">
                                    <p className="text-xs mb-2" style={{ color: p.MUTED }}>Como vai chegar:</p>
                                    <div className="flex items-start gap-2.5">
                                        <Avatar user={hipermon} size={32} />
                                        <div className="min-w-0">
                                            <p className="text-xs font-semibold mb-1" style={{ color: p.TEXT }}>{hipermon.name}</p>
                                            <div className="rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap break-words max-w-md"
                                                style={{ background: p.HOVER_ROW, color: p.TEXT, borderTopLeftRadius: 4 }}>
                                                {limpo}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}

                            <div className="flex flex-wrap items-center gap-2 mt-4">
                                <button
                                    onClick={enviar}
                                    disabled={enviando || !limpo}
                                    className="text-sm font-medium px-3.5 py-2 rounded-lg transition disabled:opacity-50"
                                    style={{ background: p.ACCENT, color: '#fff' }}
                                >
                                    {enviando ? 'Enviando…' : `Enviar para todos (${pessoas})`}
                                </button>
                                <span className="text-xs ml-auto" style={{ color: p.MUTED }}>
                                    {texto.length.toLocaleString('pt-BR')} / {textoMax.toLocaleString('pt-BR')}
                                </span>
                            </div>
                        </>
                    )}
                </Cartao>

                <Cartao
                    titulo="Já enviadas"
                    descricao={`Como toda mensagem do chat, as novidades somem depois de ${diasDeVida} dias.`}
                    p={p}
                >
                    {historico.length === 0 ? (
                        <p className="text-sm py-4 text-center" style={{ color: p.MUTED }}>Nenhuma novidade enviada ainda.</p>
                    ) : (
                        <div className="space-y-3">
                            {historico.map(h => (
                                <div key={h.em + h.texto.slice(0, 20)} className="rounded-lg px-3 py-2.5"
                                    style={{ border: `1px solid ${p.BORDER}` }}>
                                    <p className="text-xs mb-1.5" style={{ color: p.MUTED }}>
                                        {quando(h.em)} · para {h.pessoas} pessoa{h.pessoas === 1 ? '' : 's'}
                                    </p>
                                    <p className="text-sm whitespace-pre-wrap break-words" style={{ color: p.TEXT }}>{h.texto}</p>
                                </div>
                            ))}
                        </div>
                    )}
                </Cartao>
            </div>
        </Secoes>
    );
}
