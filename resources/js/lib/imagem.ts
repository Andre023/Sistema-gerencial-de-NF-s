/**
 * Redimensiona e converte a foto ANTES de enviar.
 *
 * Por que no navegador e não no servidor: a VM tem 1 GB de RAM. Uma foto de
 * 12 MP aberta no PHP vira um bitmap de ~48 MB (4000 × 3000 × 4 bytes) — com o
 * memory_limit em 128 MB, uma cabe raspando e uma de 48 MP estoura. Com o pool
 * aceitando 6 processos, três envios simultâneos derrubariam o MySQL pelo
 * OOM killer. É o mesmo motivo pelo qual o build dos assets saiu da VM.
 *
 * Aqui o celular faz o trabalho: 5 MB viram ~200 KB antes de sair do aparelho.
 * Sobra RAM no servidor e sobe muito mais rápido no wi-fi do galpão.
 *
 * Isto é OTIMIZAÇÃO, não validação: o servidor continua conferindo tipo,
 * tamanho e conteúdo de tudo que chega. Cliente nenhum é confiável.
 */

/** Lado maior da imagem depois de reduzida. Suficiente para ler um canhoto. */
const LADO_MAX = 1600;

/** Qualidade do WebP. 0.82 é o ponto em que o texto ainda fica nítido. */
const QUALIDADE = 0.82;

/** Abaixo disto não compensa converter — o overhead comeria o ganho. */
const MINIMO_PARA_CONVERTER = 300 * 1024;

export interface ResultadoOtimizacao {
    arquivo: File;
    /** false = seguiu o original (PDF, arquivo pequeno, ou a conversão falhou) */
    convertido: boolean;
    tamanhoOriginal: number;
}

/**
 * Devolve o arquivo pronto para envio.
 *
 * NUNCA lança: se qualquer etapa falhar (navegador antigo, HEIC que o canvas
 * não decodifica, imagem corrompida), devolve o original. Perder a otimização
 * é aceitável; impedir a pessoa de anexar a foto, não.
 */
export async function otimizarParaEnvio(arquivo: File): Promise<ResultadoOtimizacao> {
    const original = arquivo.size;
    const semConversao = { arquivo, convertido: false, tamanhoOriginal: original };

    // PDF passa direto: não dá para redimensionar, e recomprimir estragaria o texto
    if (!arquivo.type.startsWith('image/')) return semConversao;

    // Foto já pequena (print, imagem recortada) não vale o esforço
    if (original < MINIMO_PARA_CONVERTER) return semConversao;

    try {
        const bitmap = await carregar(arquivo);
        const { width, height } = dimensoesReduzidas(bitmap.width, bitmap.height);

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const ctx = canvas.getContext('2d');
        if (!ctx) return semConversao;

        ctx.drawImage(bitmap, 0, 0, width, height);
        if ('close' in bitmap) bitmap.close(); // libera a memória do aparelho

        const blob = await new Promise<Blob | null>(resolve =>
            canvas.toBlob(resolve, 'image/webp', QUALIDADE),
        );

        // Safari antigo devolve null (ou um PNG) quando não sabe fazer WebP
        if (!blob || blob.type !== 'image/webp') return semConversao;

        // Conversão que ENGORDOU o arquivo não serve para nada
        if (blob.size >= original) return semConversao;

        return {
            arquivo: new File([blob], trocarExtensao(arquivo.name, 'webp'), { type: 'image/webp' }),
            convertido: true,
            tamanhoOriginal: original,
        };
    } catch {
        return semConversao;
    }
}

/** createImageBitmap é o caminho rápido; <img> é a reserva para Safari antigo. */
async function carregar(arquivo: File): Promise<ImageBitmap | HTMLImageElement> {
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(arquivo);
        } catch {
            // HEIC que o navegador não decodifica cai aqui — tenta pelo <img>
        }
    }

    const url = URL.createObjectURL(arquivo);
    try {
        return await new Promise<HTMLImageElement>((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = url;
        });
    } finally {
        URL.revokeObjectURL(url);
    }
}

function dimensoesReduzidas(w: number, h: number) {
    const maior = Math.max(w, h);

    if (maior <= LADO_MAX) return { width: w, height: h };

    const fator = LADO_MAX / maior;

    return { width: Math.round(w * fator), height: Math.round(h * fator) };
}

function trocarExtensao(nome: string, extensao: string): string {
    return nome.replace(/\.[^.]+$/, '') + '.' + extensao;
}

/** "1,2 MB" — para mostrar o que foi economizado. */
export function formatarTamanho(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
