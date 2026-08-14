/**
 * O bipe dos avisos — do sino e do chat.
 *
 * Gerado na hora pelo próprio navegador (Web Audio), sem arquivo de áudio: são
 * duas senoides curtas, e um .mp3 para isso seria um download a mais em cada
 * carregamento de página.
 *
 * O AudioContext é UM só para o sistema inteiro, criado na primeira vez que
 * alguém pede som. Antes disso ele nem existe — navegador reclama de contexto
 * de áudio criado sem interação do usuário, e criar dois (um no sino, outro no
 * chat) desperdiçaria memória numa VM que já é apertada.
 *
 * Falha em silêncio de propósito: o navegador só libera som depois de algum
 * clique na página, e som é enfeite — nunca deve atrapalhar o aviso em si.
 */

let contexto: AudioContext | null = null;

/** As duas notas do sino: sobem. */
const AVISO = [880, 1175];

/** As do chat: mais graves e mais próximas, para não confundir com o sino. */
const MENSAGEM = [660, 880];

function tocar(notas: number[], volume: number): void {
    try {
        const Ctor = window.AudioContext ?? (window as any).webkitAudioContext;
        if (!Ctor) return;

        const ctx = contexto ?? (contexto = new Ctor());
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});

        notas.forEach((hz, i) => {
            const osc = ctx.createOscillator();
            const vol = ctx.createGain();
            const t0  = ctx.currentTime + i * 0.12;

            osc.type = 'sine';
            osc.frequency.value = hz;
            vol.gain.setValueAtTime(0.0001, t0);
            vol.gain.exponentialRampToValueAtTime(volume, t0 + 0.02);
            vol.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.16);

            osc.connect(vol).connect(ctx.destination);
            osc.start(t0);
            osc.stop(t0 + 0.18);
        });
    } catch {
        // som é enfeite; nunca deve atrapalhar o aviso
    }
}

/** Notificação de nota (o sino). */
export function biparAviso(): void {
    tocar(AVISO, 0.12);
}

/**
 * Mensagem nova no chat. Mais baixo que o do sino de propósito: chega com mais
 * frequência, e aviso de nota é o que não pode passar despercebido.
 */
export function biparMensagem(): void {
    tocar(MENSAGEM, 0.09);
}
