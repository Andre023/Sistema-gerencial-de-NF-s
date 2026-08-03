// Emoji como imagem auto-hospedada (conjunto Noto/Google) — desenho IDENTICO em
// Windows 10, Windows 11, celular e qualquer navegador (a fonte de emoji do SO
// nao entra em jogo, entao nada de "quadradinho" nem visual diferente por versao).
//
// Os SVGs moram em public/emoji/ e sao servidos como arquivo estatico, direto da
// raiz do site — nao passam pelo bundle.
//
// Antes eles passavam: um import.meta.glob EAGER fazia o Vite gerar um mapa com
// as 807 URLs hasheadas dentro do chunk do <Avatar>. Eram 82 KB de JavaScript
// que TODA pagina baixava (o avatar esta na navbar) so para descobrir o endereco
// de um punhado de imagens. Montando a URL em runtime, o mapa deixa de existir e
// o navegador baixa apenas o emoji que de fato aparece na tela.
//
// Os arquivos sao gerados por scripts/gen-emoji.mjs a partir do conjunto do
// picker (EMOJIS_BASE x tons de pele, em lib/avatares.ts). Se um dia adicionar
// emoji novo la, rode o script de novo para trazer o SVG dele.

const ZWJ = 0x200d;   // Zero Width Joiner (une profissao/genero)
const VS16 = 0xfe0f;  // seletor de variacao (forca desenho de emoji)

/**
 * Codepoints do nome do arquivo: quando NAO ha ZWJ, o seletor VS16 e removido.
 * Tem que dar o mesmo resultado do gerador, senao erra o SVG.
 */
function codepoint(emoji: string): string {
    const partes = Array.from(emoji);
    const temZwj = partes.some(ch => ch.codePointAt(0) === ZWJ);
    const usados = temZwj ? partes : partes.filter(ch => ch.codePointAt(0) !== VS16);
    return usados.map(ch => ch.codePointAt(0)!.toString(16)).join('-');
}

/**
 * URL do SVG do emoji.
 *
 * Daqui nao da para saber se o arquivo existe — quem cuida disso e o <Emoji>,
 * que cai no emoji nativo do SO se a imagem nao carregar. Era o unico servico
 * que o mapa de 82 KB prestava, e um onError faz o mesmo de graca.
 */
export function emojiUrl(emoji: string): string {
    return `/emoji/${codepoint(emoji)}.svg`;
}
