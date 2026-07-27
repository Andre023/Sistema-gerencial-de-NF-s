// Gera os SVGs Twemoji de resources/js/assets/twemoji/ a partir do conjunto do
// picker (EMOJIS_BASE x tons de pele). Rode de novo quando mexer na lista de
// emojis em resources/js/lib/avatares.ts.
//
// Como rodar (o pacote de assets conflita com os peer deps do projeto, entao
// instale isolado):
//   mkdir -p /tmp/tw && cd /tmp/tw && npm init -y && npm i @discordapp/twemoji
//   TWEMOJI_SVG_DIR=/tmp/tw/node_modules/@discordapp/twemoji/dist/svg \
//     node <projeto>/scripts/gen-twemoji.mjs
//
// (rode a partir da raiz do projeto; a saida vai para resources/js/assets/twemoji/)

import { existsSync, mkdirSync, copyFileSync, rmSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

// ── ESPELHO de resources/js/lib/avatares.ts (mantenha em sincronia) ──
const EMOJIS_BASE = [
    '🙋', '🙋‍♀️', '🙋‍♂️', '🙆‍♀️', '🙆‍♂️', '💁‍♀️', '💁‍♂️', '🤷‍♀️', '🤷‍♂️',
    '🧑‍💼', '👨‍💼', '👩‍💼', '👷', '👷‍♀️', '🧑‍🔧', '🧑‍🍳', '🧑‍🌾', '🧑‍🏫',
    '🧑‍💻', '🧑‍🔬', '🧑‍⚕️', '🕵️', '👮', '💂', '🧑‍✈️', '🧑‍🚀', '🤵',
    '🦸', '🦸‍♀️', '🦹', '🧙', '🧙‍♀️', '🧛', '🧝', '🧚', '🧞', '🥷',
];
const TONS_PELE = ['', '🏻', '🏼', '🏽', '🏾', '🏿'];
const SEM_TOM = new Set(['🧞']);
function aplicarTom(base, tomIdx) {
    if (tomIdx <= 0 || SEM_TOM.has(base)) return base;
    const cps = Array.from(base);
    return cps[0] + TONS_PELE[tomIdx] + cps.slice(1).join('');
}

// ── mesma regra do runtime (lib/twemoji.ts): sem ZWJ, remove o VS16 ──
const ZWJ = 0x200d, VS16 = 0xfe0f;
function nomeArquivo(emoji) {
    const partes = Array.from(emoji);
    const temZwj = partes.some(ch => ch.codePointAt(0) === ZWJ);
    const usados = temZwj ? partes : partes.filter(ch => ch.codePointAt(0) !== VS16);
    return usados.map(ch => ch.codePointAt(0).toString(16)).join('-') + '.svg';
}

const svgDir = process.env.TWEMOJI_SVG_DIR
    || 'node_modules/@discordapp/twemoji/dist/svg';
const outDir = 'resources/js/assets/twemoji';

if (!existsSync(svgDir)) {
    console.error(`Pasta de SVGs nao encontrada: ${svgDir}\nDefina TWEMOJI_SVG_DIR (veja o cabecalho).`);
    process.exit(1);
}

// zera a saida para nao deixar SVG orfao de emoji removido
rmSync(outDir, { recursive: true, force: true });
mkdirSync(outDir, { recursive: true });

const emojis = new Set();
for (const base of EMOJIS_BASE) {
    const tons = SEM_TOM.has(base) ? [0] : [0, 1, 2, 3, 4, 5];
    for (const t of tons) emojis.add(aplicarTom(base, t));
}

let ok = 0; const faltando = [];
for (const e of emojis) {
    const nome = nomeArquivo(e);
    const src = join(svgDir, nome);
    if (existsSync(src)) { copyFileSync(src, join(outDir, nome)); ok++; }
    else faltando.push(`${e} -> ${nome}`);
}

console.log(`emojis do picker: ${emojis.size} | SVGs copiados: ${ok} | em ${outDir}: ${readdirSync(outDir).length}`);
if (faltando.length) { console.log(`FALTANDO (${faltando.length}):`); faltando.forEach(f => console.log('  ' + f)); }
