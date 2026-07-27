// Twemoji: renderiza emojis como imagens auto-hospedadas — desenho IDENTICO em
// Windows 10, Windows 11, celular e qualquer navegador (a fonte de emoji do SO
// nao entra em jogo, entao nada de "quadradinho" nem visual diferente por versao).
//
// Os SVGs em assets/twemoji/ sao gerados por scripts/gen-twemoji.mjs a partir do
// conjunto do picker (EMOJIS_BASE x tons de pele, em lib/avatares.ts). Se um dia
// adicionar emoji novo la, rode o script de novo para trazer o SVG dele.

// Vite empacota so os SVGs referenciados e devolve a URL final (hasheada).
const modulos = import.meta.glob('../assets/twemoji/*.svg', {
    eager: true, query: '?url', import: 'default',
}) as Record<string, string>;

// mapa: "1f64b.svg" -> URL final
const porArquivo: Record<string, string> = {};
for (const caminho in modulos) {
    const nome = caminho.split('/').pop();
    if (nome) porArquivo[nome] = modulos[caminho];
}

const ZWJ = 0x200d;   // Zero Width Joiner (une profissao/genero)
const VS16 = 0xfe0f;  // seletor de variacao (forca desenho de emoji)

/**
 * Codepoints no padrao twemoji: quando NAO ha ZWJ, o seletor VS16 e removido do
 * nome do arquivo. Tem que dar o mesmo resultado do gerador, senao erra o SVG.
 */
function codepoint(emoji: string): string {
    const partes = Array.from(emoji);
    const temZwj = partes.some(ch => ch.codePointAt(0) === ZWJ);
    const usados = temZwj ? partes : partes.filter(ch => ch.codePointAt(0) !== VS16);
    return usados.map(ch => ch.codePointAt(0)!.toString(16)).join('-');
}

/** URL do SVG twemoji do emoji, ou null se ele nao estiver no pacote. */
export function twemojiUrl(emoji: string): string | null {
    return porArquivo[codepoint(emoji) + '.svg'] ?? null;
}
