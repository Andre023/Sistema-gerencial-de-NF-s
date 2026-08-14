// O catálogo do seletor de emoji do chat, e a lista de recentes.
//
// Os emojis em si vêm de emojis-chat.json, que é a fonte única: o mesmo arquivo
// alimenta esta tela E o gerador de SVGs (scripts/gen-emoji.mjs). O desenho é o
// conjunto Noto (o emoji do Google), auto-hospedado em public/emoji/ — nada de
// API externa, nem de aparência diferente entre Windows 10 e 11.

import catalogo from './emojis-chat.json';

export interface CategoriaEmoji {
    id: string;
    rotulo: string;
    /** O emoji que serve de ícone da aba. */
    icone: string;
    /** [emoji, nome] — o nome é o tooltip e o que a busca procura. */
    emojis: [string, string][];
}

// O TypeScript lê os pares do JSON como string[][]; o `unknown` no meio é o
// que permite afirmar a tupla [emoji, nome] sem afrouxar o tipo do resto.
export const CATEGORIAS: CategoriaEmoji[] = catalogo.categorias as unknown as CategoriaEmoji[];

/** Todos os pares, achatados — a base da busca. */
const TODOS: [string, string][] = CATEGORIAS.flatMap(c => c.emojis);

/**
 * Busca por nome, sem acento e sem caso.
 *
 * Os nomes no JSON já são escritos sem acento de propósito: assim "cafe" e
 * "café" encontram ☕ sem precisar normalizar os dois lados a cada tecla.
 * Aqui normalizamos só o que a pessoa digitou.
 */
export function buscar(termo: string): [string, string][] {
    // NFD separa a letra do acento, e o intervalo abaixo sao as marcas de
    // acento que sobram soltas. Tirar essas marcas faz "Cafe" e "Café"
    // virarem a mesma coisa, e casarem com o nome escrito sem acento no JSON.
    //
    // O intervalo vai escrito com escapes (u0300 a u036f, sem os zeros que o
    // JS exige) e nao com os caracteres em si: acento solto e invisivel no
    // editor, e qualquer copia entre ferramentas pode come-lo sem ninguem
    // notar. O sintoma seria a busca com acento parar de achar, calada.
    const t = termo.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    if (!t) return [];

    // Quem começa com o termo vem primeiro: digitar "ca" mostra "café" antes de
    // "bolo de aniversario", que também contém "ca" no meio.
    const comeca: [string, string][] = [];
    const contem: [string, string][] = [];

    for (const par of TODOS) {
        const nome = par[1];
        if (nome.startsWith(t)) comeca.push(par);
        else if (nome.includes(t)) contem.push(par);
    }

    return [...comeca, ...contem];
}

// ─── Recentes ─────────────────────────────────────────────────────────────────

const CHAVE = 'nfs_emoji_recentes';
const LIMITE = 24;

/**
 * Os últimos usados, do mais recente para o mais antigo.
 *
 * No localStorage e não no banco: é preferência de teclado, não dado do
 * sistema. Não vale uma coluna nem uma requisição — e o pior que acontece se
 * sumir é a grade voltar a começar vazia.
 */
export function recentes(): string[] {
    try {
        const cru = JSON.parse(localStorage.getItem(CHAVE) ?? '[]');

        // Filtra contra o catálogo: emoji removido daqui não pode continuar
        // aparecendo nos recentes de quem já o usou.
        return Array.isArray(cru)
            ? cru.filter((e: unknown): e is string => typeof e === 'string' && TODOS.some(p => p[0] === e))
            : [];
    } catch {
        return [];
    }
}

/** Põe o emoji na frente da lista (sem repetir) e guarda. */
export function guardarRecente(emoji: string): string[] {
    const lista = [emoji, ...recentes().filter(e => e !== emoji)].slice(0, LIMITE);

    try {
        localStorage.setItem(CHAVE, JSON.stringify(lista));
    } catch {
        // Cota cheia ou armazenamento bloqueado: os recentes são conveniência,
        // e o seletor funciona igual sem eles.
    }

    return lista;
}

/** O nome de um emoji, para o tooltip dos recentes (que guardam só o caractere). */
export function nomeDe(emoji: string): string {
    return TODOS.find(p => p[0] === emoji)?.[1] ?? '';
}
