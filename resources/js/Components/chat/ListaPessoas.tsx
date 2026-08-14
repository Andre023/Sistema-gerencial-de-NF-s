import { format, parseISO, isToday } from 'date-fns';
import { PessoaChat } from '@/types';
import { Palette } from '@/lib/tema';
import Avatar from '@/Components/painel/Avatar';
import { useChat } from './ChatProvider';

/** Hoje mostra a hora; antes disso, a data — igual à lista do WhatsApp. */
const quando = (iso: string) => {
    try {
        const d = parseISO(iso);
        return isToday(d) ? format(d, 'HH:mm') : format(d, 'dd/MM');
    } catch {
        return '';
    }
};

/**
 * A lista de pessoas da barra expandida.
 *
 * Mostra TODO MUNDO, não só quem está online: mensagem é para ser lida quando a
 * pessoa voltar, e uma lista que esconde quem saiu para almoçar seria uma lista
 * onde não se consegue mandar recado.
 *
 * A ordem é a do WhatsApp, e resolve sozinha o que interessa primeiro:
 *   1. quem tem mensagem por ler
 *   2. quem tem conversa mais recente
 *   3. quem está online
 *   4. o resto, por nome
 */
export default function ListaPessoas({ online, p }: { online: Set<number>; p: Palette }) {
    const { pessoas, carregandoLista, abrirConversa } = useChat();

    if (carregandoLista && !pessoas) {
        return <p className="text-xs text-center py-8" style={{ color: p.MUTED }}>Carregando…</p>;
    }

    if (!pessoas?.length) {
        return <p className="text-xs text-center py-8 px-3" style={{ color: p.MUTED }}>
            Nenhuma outra pessoa cadastrada.
        </p>;
    }

    const ordenadas = [...pessoas].sort((a, b) => {
        if ((a.nao_lidas > 0) !== (b.nao_lidas > 0)) return a.nao_lidas > 0 ? -1 : 1;

        const ea = a.ultima?.em ?? '';
        const eb = b.ultima?.em ?? '';
        if (ea !== eb) return eb.localeCompare(ea);

        const oa = online.has(a.id);
        const ob = online.has(b.id);
        if (oa !== ob) return oa ? -1 : 1;

        return a.nome.localeCompare(b.nome);
    });

    return (
        <div className="py-1">
            {ordenadas.map(pessoa => (
                <button
                    key={pessoa.id}
                    onClick={() => abrirConversa(pessoa.id)}
                    className="w-full flex items-center gap-2.5 px-3 py-2 text-left transition"
                    style={{ background: 'transparent' }}
                    onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                >
                    <div className="relative shrink-0">
                        <Avatar user={{ name: pessoa.nome, avatar: pessoa.avatar }} size={36} />
                        {online.has(pessoa.id) && (
                            <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full"
                                style={{ background: p.GREEN, border: `2px solid ${p.SURFACE}` }} />
                        )}
                    </div>

                    <div className="flex-1 min-w-0">
                        <div className="flex items-baseline gap-1.5">
                            <span className="text-[13px] font-medium truncate flex-1" style={{ color: p.TEXT }}>
                                {pessoa.nome}
                            </span>
                            {pessoa.ultima && (
                                <span className="text-[10px] shrink-0"
                                    style={{ color: pessoa.nao_lidas ? p.GREEN : p.MUTED }}>
                                    {quando(pessoa.ultima.em)}
                                </span>
                            )}
                        </div>

                        <div className="flex items-center gap-1.5">
                            <span className="text-[11px] truncate flex-1"
                                style={{ color: p.MUTED, fontWeight: pessoa.nao_lidas ? 600 : 400 }}>
                                {pessoa.ultima
                                    ? (pessoa.ultima.minha ? `Você: ${pessoa.ultima.previa}` : pessoa.ultima.previa)
                                    : ''}
                            </span>

                            {pessoa.nao_lidas > 0 && (
                                <span className="shrink-0 min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold
                                                 flex items-center justify-center text-white"
                                    style={{ background: p.GREEN }}>
                                    {pessoa.nao_lidas > 99 ? '99+' : pessoa.nao_lidas}
                                </span>
                            )}
                        </div>
                    </div>
                </button>
            ))}
        </div>
    );
}
