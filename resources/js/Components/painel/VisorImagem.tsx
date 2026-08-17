import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { Palette } from '@/lib/tema';
import { formatarTamanho } from '@/lib/imagem';
import Icone from '@/Components/painel/Icone';

/**
 * A foto em tela cheia, por cima do sistema.
 *
 * Serve as duas telas que mostram foto: os anexos da nota e o chat. Antes o
 * clique abria uma aba nova — a pessoa saía da fila, olhava, e tinha de achar o
 * caminho de volta. Para uma foto de avaria que se vê em dois segundos, era
 * mais trabalho do que a própria tarefa.
 *
 * Vai por PORTAL, direto no <body>, por dois motivos: no chat ele nasce dentro
 * da barra lateral (que é `sticky` e teria como virar berço do `position:
 * fixed`), e nos anexos ele nasce DENTRO DE OUTRO MODAL — sem o portal ficaria
 * preso no cartão dele, atrás do próprio fundo escurecido.
 *
 * PDF não passa por aqui, e é de propósito: continua baixando para o leitor do
 * sistema. PDF exibido na mesma origem pode rodar JavaScript embutido.
 */
export default function VisorImagem({ url, urlDownload, nome, tamanho, onFechar, p }: {
    url: string;
    /**
     * Endereço para o botão de baixar, quando for diferente do de exibir.
     *
     * Nos anexos da nota a mesma rota serve os dois, mudando só o `?baixar=1`:
     * sem ele o servidor manda `inline` e o navegador abre em vez de salvar.
     * No chat a URL já é um blob local, e aí um endereço só resolve.
     */
    urlDownload?: string;
    nome: string;
    /** bytes — mostrado ao lado do nome */
    tamanho: number;
    onFechar: () => void;
    p: Palette;
}) {
    /*
     * "Caber na tela" ou "tamanho real".
     *
     * Não é enfeite: a foto é reduzida a 1600px no envio, e cabendo numa tela
     * de 900px ela aparece bem menor que isso. Sem o tamanho real, ler o número
     * de uma nota fotografada seria impossível — e na aba nova, que este visor
     * substitui, o navegador dava esse zoom de graça.
     */
    const [real, setReal] = useState(false);

    useEffect(() => {
        const tecla = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') return;
            // O seletor de emoji também escuta Esc; sem isto os dois fechariam
            // com uma tecla só.
            e.stopPropagation();
            onFechar();
        };

        window.addEventListener('keydown', tecla, true);

        // Trava a rolagem do que está atrás: sem isto a roda do mouse rola a
        // fila de notas enquanto a foto está aberta por cima.
        const rolagemAntes = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            window.removeEventListener('keydown', tecla, true);
            document.body.style.overflow = rolagemAntes;
        };
    }, [onFechar]);

    return createPortal(
        <div className="fixed inset-0 z-[60] flex flex-col">

            {/* Fundo — clicar fora fecha */}
            <div className="absolute inset-0 bg-black/80 backdrop-blur-sm" onClick={onFechar} />

            {/* Barra de cima: o que é, e o que dá para fazer */}
            <div className="relative flex items-center gap-3 px-4 py-3 shrink-0"
                style={{ background: 'rgba(0,0,0,0.45)' }}>

                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium truncate text-white">{nome}</p>
                    <p className="text-[11px] text-white/60">{formatarTamanho(tamanho)}</p>
                </div>

                <button
                    onClick={() => setReal(r => !r)}
                    title={real ? 'Ajustar à tela' : 'Ver em tamanho real'}
                    className="p-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition shrink-0"
                >
                    <Icone
                        path={real
                            ? 'M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25'
                            : 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM13.5 10.5h-6'}
                        className="w-5 h-5"
                    />
                </button>

                {/* O download importa mais aqui do que numa foto comum: passados
                    poucos dias o servidor apaga o arquivo, e a cópia da máquina
                    passa a ser a única que existe. */}
                <a
                    href={urlDownload ?? url}
                    download={nome}
                    title="Baixar"
                    className="p-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition shrink-0"
                >
                    <Icone path="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"
                        className="w-5 h-5" />
                </a>

                <button onClick={onFechar} title="Fechar (Esc)"
                    className="p-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition shrink-0">
                    <Icone path="M6 18L18 6M6 6l12 12" className="w-5 h-5" />
                </button>
            </div>

            {/*
                A foto. Clicar nela alterna o zoom; clicar em volta fecha.

                Os dois modos usam layouts DIFERENTES de propósito:

                • caber na tela — flex centralizado, e a imagem se limita ao
                  espaço disponível;
                • tamanho real — bloco com rolagem, e a imagem NÃO pode encolher.

                Tentar fazer os dois com flex não funciona: item de flex encolhe
                por padrão, então no "tamanho real" a imagem era espremida para
                caber na largura da janela — 1353px de uma foto de 1600px, ou
                seja, o zoom não mostrava o tamanho real coisa nenhuma.
            */}
            <div
                className={real
                    ? 'relative flex-1 min-h-0 overflow-auto p-4'
                    : 'relative flex-1 min-h-0 flex items-center justify-center overflow-hidden p-4'}
                onClick={onFechar}
            >
                <img
                    src={url}
                    alt={nome}
                    onClick={e => { e.stopPropagation(); setReal(r => !r); }}
                    className={real
                        ? 'block m-auto max-w-none cursor-zoom-out'
                        : 'max-w-full max-h-full object-contain cursor-zoom-in'}
                    style={real ? undefined : { boxShadow: '0 8px 40px rgba(0,0,0,0.5)' }}
                />
            </div>
        </div>,
        document.body,
    );
}
