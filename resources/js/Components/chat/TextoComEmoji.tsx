import { useMemo } from 'react';
import Emoji from '@/Components/painel/Emoji';

/**
 * O texto da mensagem, com os emojis desenhados pelo conjunto Noto.
 *
 * Sem isto, o emoji sai pela fonte do sistema operacional — e aí o mesmo
 * caractere é uma coisa no Windows 11, outra no Windows 10 e um QUADRADINHO
 * nas máquinas mais antigas, que não conhecem os emojis recentes (🫡, 🫰).
 * Alguém mandaria continência e o colega veria um retângulo vazio.
 *
 * É o mesmo motivo pelo qual os avatares já são imagem (ver lib/emoji.ts): o
 * seletor mostra um desenho, e a mensagem tem de mostrar exatamente aquele.
 */
export default function TextoComEmoji({ texto, tamanho = 19 }: {
    texto: string;
    /** Lado do emoji em px — combine com a altura da linha do texto ao redor. */
    tamanho?: number;
}) {
    const pedacos = useMemo(() => separar(texto), [texto]);

    return (
        <>
            {pedacos.map((pedaco, i) => pedaco.emoji
                ? <Emoji key={i} emoji={pedaco.valor} size={tamanho} />
                : <span key={i}>{pedaco.valor}</span>)}
        </>
    );
}

interface Pedaco { emoji: boolean; valor: string }

/**
 * Quebra o texto em trechos de letra e trechos de emoji.
 *
 * Usa o Intl.Segmenter porque emoji NÃO é um caractere: 👩‍🔧 são quatro
 * codepoints unidos por ZWJ, e 👍🏽 são dois. Percorrer a string por caractere
 * partiria esses grupos no meio e desenharia as peças soltas — uma mulher, uma
 * chave inglesa. O Segmenter entrega o "grafema", que é o que a pessoa vê como
 * um símbolo só.
 *
 * Onde não houver Segmenter (navegador antigo), devolve o texto inteiro como
 * texto: os emojis saem no desenho do sistema, que é o comportamento de antes
 * — pior, mas nunca quebrado.
 */
function separar(texto: string): Pedaco[] {
    if (typeof Intl === 'undefined' || !('Segmenter' in Intl)) {
        return [{ emoji: false, valor: texto }];
    }

    const segmenter = new (Intl as any).Segmenter('pt', { granularity: 'grapheme' });
    const pedacos: Pedaco[] = [];

    for (const { segment } of segmenter.segment(texto)) {
        const ehEmoji = PICTOGRAFICO.test(segment);
        const ultimo  = pedacos[pedacos.length - 1];

        // Junta letras vizinhas num pedaço só: um <span> por letra encheria a
        // árvore do React de nós à toa numa mensagem de 2000 caracteres.
        if (ultimo && ultimo.emoji === ehEmoji && !ehEmoji) {
            ultimo.valor += segment;
        } else {
            pedacos.push({ emoji: ehEmoji, valor: segment });
        }
    }

    return pedacos;
}

/**
 * Um grafema conta como emoji se contiver algum pictograma.
 *
 * `Extended_Pictographic` cobre de 😀 a ⚙️ sem listar nada à mão. O teste é por
 * "contém" e não por "é igual" de propósito: o grafema pode trazer junto o
 * seletor de variação (️) ou o modificador de tom, que não são pictogramas.
 *
 * Fica fora do `separar` para o regex ser compilado uma vez só, e não a cada
 * mensagem redesenhada.
 */
const PICTOGRAFICO = /\p{Extended_Pictographic}/u;
