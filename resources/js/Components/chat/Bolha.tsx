import { format, parseISO } from 'date-fns';
import { Mensagem } from '@/types';
import { Palette } from '@/lib/tema';
import Icone from '@/Components/painel/Icone';
import AnexoDaMensagem from './AnexoDaMensagem';

const hora = (iso: string) => {
    try { return format(parseISO(iso), 'HH:mm'); } catch { return ''; }
};

/**
 * Uma mensagem. Minhas à direita e em azul, as do outro à esquerda — a mesma
 * convenção do WhatsApp, que todo mundo aqui já lê sem precisar aprender.
 *
 * O ✓ só existe do meu lado: é informação sobre o que EU mandei.
 *   ✓  entregue    ✓✓ lido    ⏱ ainda subindo    ⚠ falhou
 */
export default function Bolha({ mensagem, minha, lido, p }: {
    mensagem: Mensagem;
    minha: boolean;
    lido: boolean;
    p: Palette;
}) {
    const temTexto = !!mensagem.texto;
    const fundo    = minha ? p.ACCENT : p.HOVER_ROW;
    const cor      = minha ? '#ffffff' : p.TEXT;

    return (
        <div className={`flex ${minha ? 'justify-end' : 'justify-start'}`}>
            <div className="max-w-[85%] rounded-2xl px-3 py-2 space-y-1.5"
                style={{
                    background: fundo,
                    color: cor,
                    // O "rabinho" achatado do lado de quem falou
                    borderBottomRightRadius: minha ? 4 : undefined,
                    borderBottomLeftRadius: minha ? undefined : 4,
                    opacity: mensagem.pendente ? 0.65 : 1,
                }}>

                {mensagem.anexo && (
                    <AnexoDaMensagem
                        mensagemId={mensagem.id}
                        anexo={mensagem.anexo}
                        minha={minha}
                        p={p}
                    />
                )}

                {temTexto && (
                    <p className="text-sm whitespace-pre-wrap break-words leading-snug">
                        {mensagem.texto}
                    </p>
                )}

                <div className="flex items-center justify-end gap-1 -mb-0.5">
                    <span className="text-[10px]" style={{ opacity: 0.7 }}>
                        {hora(mensagem.created_at)}
                    </span>

                    {minha && <Marca mensagem={mensagem} lido={lido} />}
                </div>
            </div>
        </div>
    );
}

/** O indicador de estado das minhas mensagens. */
function Marca({ mensagem, lido }: { mensagem: Mensagem; lido: boolean }) {
    if (mensagem.falhou) {
        return (
            <span title="Não foi enviada">
                <Icone path="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" className="w-3 h-3" />
            </span>
        );
    }

    if (mensagem.pendente) {
        return (
            <span title="Enviando">
                <Icone path="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" className="w-3 h-3" />
            </span>
        );
    }

    // Dois riscos sobrepostos formam o ✓✓ sem precisar de ícone novo
    return (
        <span title={lido ? 'Lida' : 'Entregue'} className="relative inline-flex items-center"
            style={{ opacity: lido ? 1 : 0.65, width: lido ? 16 : 11 }}>
            <Icone path="M5 13l4 4L19 7" className="w-3 h-3 absolute left-0" />
            {lido && <Icone path="M5 13l4 4L19 7" className="w-3 h-3 absolute left-[5px]" />}
        </span>
    );
}
