// Gera os SVGs de public/emoji/ (conjunto Noto/Google) a partir de DUAS listas:
//
//   1. os avatares — EMOJIS_BASE x tons de pele (espelho de lib/avatares.ts)
//   2. o seletor do chat — resources/js/lib/emojis-chat.json (fonte unica, lida
//      daqui e pelo frontend; nao ha espelho para sair de sincronia)
//
// Rode de novo ao mexer em qualquer uma das duas.
//
// ATENCAO: o script LIMPA public/emoji/ antes de baixar. As duas listas tem de
// entrar na mesma rodada — gerar so uma apagaria os SVGs da outra e deixaria
// metade do sistema com emoji do sistema operacional.
//
// Eles ficam em public/ (e nao em resources/) de proposito: sao servidos como
// arquivo estatico, sem passar pelo bundle. Ver o comentario em lib/emoji.ts.
//
// Baixa direto do repositorio oficial googlefonts/noto-emoji (via jsdelivr) e
// salva cada SVG com o NOME que o runtime espera (lib/emoji.ts). Precisa de rede.
//
// Como rodar (a partir da raiz do projeto):
//   node scripts/gen-emoji.mjs

import { mkdirSync, writeFileSync, rmSync, readdirSync, readFileSync } from 'node:fs';

// ── ESPELHO de resources/js/lib/avatares.ts (mantenha em sincronia) ──
const EMOJIS_BASE = [
    '🧑', '👨', '👩',
    '👱', '👱‍♂️', '👱‍♀️',
    '🧑‍🦰', '👨‍🦰', '👩‍🦰',
    '🧑‍🦱', '👨‍🦱', '👩‍🦱',
    '🧑‍🦳', '👨‍🦳', '👩‍🦳',
    '🧑‍🦲', '👨‍🦲', '👩‍🦲',
    '🧓', '👴', '👵',
    '🙋', '🙋‍♂️', '🙋‍♀️',
    '🙆', '🙆‍♂️', '🙆‍♀️',
    '💁', '💁‍♂️', '💁‍♀️',
    '🤷', '🤷‍♂️', '🤷‍♀️',
    '🧑‍💼', '👨‍💼', '👩‍💼',
    '🧑‍🎓', '👨‍🎓', '👩‍🎓',
    '🧑‍🏫', '👨‍🏫', '👩‍🏫',
    '🧑‍⚖️', '👨‍⚖️', '👩‍⚖️',
    '🧑‍🌾', '👨‍🌾', '👩‍🌾',
    '🧑‍🍳', '👨‍🍳', '👩‍🍳',
    '🧑‍🔧', '👨‍🔧', '👩‍🔧',
    '🧑‍🏭', '👨‍🏭', '👩‍🏭',
    '🧑‍💻', '👨‍💻', '👩‍💻',
    '🧑‍🔬', '👨‍🔬', '👩‍🔬',
    '🧑‍🎨', '👨‍🎨', '👩‍🎨',
    '🧑‍🚒', '👨‍🚒', '👩‍🚒',
    '🧑‍✈️', '👨‍✈️', '👩‍✈️',
    '🧑‍🚀', '👨‍🚀', '👩‍🚀',
    '🧑‍⚕️', '👨‍⚕️', '👩‍⚕️',
    '🧑‍🎤', '👨‍🎤', '👩‍🎤',
    '👷', '👷‍♂️', '👷‍♀️',
    '💂', '💂‍♂️', '💂‍♀️',
    '🕵️', '🕵️‍♂️', '🕵️‍♀️',
    '👮', '👮‍♂️', '👮‍♀️',
    '🤵', '🤵‍♂️', '🤵‍♀️',
    '👰', '👰‍♂️', '👰‍♀️',
    '🫅', '🤴', '👸',
    '👳', '👳‍♂️', '👳‍♀️',
    '🧕', '👲', '💃', '🕺',
    '🦸', '🦸‍♂️', '🦸‍♀️',
    '🦹', '🦹‍♂️', '🦹‍♀️',
    '🧙', '🧙‍♂️', '🧙‍♀️',
    '🧝', '🧝‍♂️', '🧝‍♀️',
    '🧛', '🧛‍♂️', '🧛‍♀️',
    '🧚', '🧚‍♂️', '🧚‍♀️',
    '🧜', '🧜‍♂️', '🧜‍♀️',
    '🥷', '🎅', '🤶', '🧑‍🎄',
    '🧞', '🧟', '🧌',
];
const TONS_PELE = ['', '🏻', '🏼', '🏽', '🏾', '🏿'];
const SEM_TOM = new Set(['🧞', '🧟', '🧌']);
function aplicarTom(base, tomIdx) {
    if (tomIdx <= 0 || SEM_TOM.has(base)) return base;
    const cps = Array.from(base);
    return cps[0] + TONS_PELE[tomIdx] + cps.slice(1).join('');
}

const ZWJ = 0x200d, VS16 = 0xfe0f;
// Nome que o RUNTIME procura (lib/emoji.ts): hifen; mantem fe0f quando ha ZWJ.
function runtimeName(emoji) {
    const partes = Array.from(emoji);
    const temZwj = partes.some(ch => ch.codePointAt(0) === ZWJ);
    const usados = temZwj ? partes : partes.filter(ch => ch.codePointAt(0) !== VS16);
    return usados.map(ch => ch.codePointAt(0).toString(16)).join('-');
}
// Nome do arquivo no Noto: emoji_u..., underscore, SEM o seletor fe0f.
function notoName(emoji) {
    const cps = Array.from(emoji).map(ch => ch.codePointAt(0)).filter(cp => cp !== VS16);
    return 'emoji_u' + cps.map(cp => cp.toString(16)).join('_');
}

const emojis = new Set();

// 1. Avatares: cada base rende 6 variacoes (padrao + 5 tons de pele)
for (const base of EMOJIS_BASE) {
    const tons = SEM_TOM.has(base) ? [0] : [0, 1, 2, 3, 4, 5];
    for (const t of tons) emojis.add(aplicarTom(base, t));
}
const doAvatar = emojis.size;

// 2. Seletor do chat: sem tons de pele — o campo de mensagem nao tem seletor de
//    tom, e multiplicar por 6 baixaria centenas de SVGs que ninguem usaria.
const catalogo = JSON.parse(readFileSync('resources/js/lib/emojis-chat.json', 'utf8'));
for (const cat of catalogo.categorias) {
    for (const [emoji] of cat.emojis) emojis.add(emoji);
}
const doChat = emojis.size - doAvatar;

const outDir = 'public/emoji';
const cdn = 'https://cdn.jsdelivr.net/gh/googlefonts/noto-emoji/svg';

rmSync(outDir, { recursive: true, force: true });   // nao deixa SVG orfao
mkdirSync(outDir, { recursive: true });

async function baixar(emoji) {
    const rn = runtimeName(emoji);
    let res = await fetch(`${cdn}/${notoName(emoji)}.svg`);
    if (!res.ok) { // fallback raro: nome mantendo o fe0f
        const alt = 'emoji_u' + Array.from(emoji).map(ch => ch.codePointAt(0).toString(16)).join('_');
        res = await fetch(`${cdn}/${alt}.svg`);
    }
    if (!res.ok) return { emoji, status: res.status, ok: false };
    writeFileSync(`${outDir}/${rn}.svg`, await res.text());
    return { ok: true };
}

const lista = [...emojis];
const faltando = [];
for (let i = 0; i < lista.length; i += 20) {
    const r = await Promise.all(lista.slice(i, i + 20).map(baixar));
    r.forEach(x => { if (!x.ok) faltando.push(`${x.emoji} -> ${x.status}`); });
}
console.log(
    `avatares: ${doAvatar} | chat: ${doChat} | total: ${emojis.size}`
    + ` | baixados: ${readdirSync(outDir).length} | faltando: ${faltando.length}`,
);
faltando.forEach(f => console.log('  ' + f));

// Emoji que nao baixou nao quebra a tela (o <Emoji> cai no desenho do sistema
// operacional), mas quebra a promessa de "igual em toda maquina" — entao sai
// com erro, para quem rodou o script perceber em vez de descobrir na tela.
if (faltando.length) process.exitCode = 1;
