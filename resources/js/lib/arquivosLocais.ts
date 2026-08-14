/**
 * A cópia local dos anexos do chat.
 *
 * O servidor guarda foto e documento por poucos dias (Mensagem::DIAS_NO_SERVIDOR)
 * e depois apaga — é o que impede o disco da VM de virar depósito. Para a
 * conversa não perder o conteúdo junto, cada navegador guarda a própria cópia
 * do arquivo assim que o exibe pela primeira vez.
 *
 * Onde: IndexedDB. É o único armazenamento do navegador que aceita binário de
 * megabytes (localStorage é texto e tem ~5 MB no total) e que sobrevive a
 * fechar a aba.
 *
 * O que isso significa na prática, e é bom ter claro:
 *   • a cópia pertence ÀQUELE navegador, naquela máquina
 *   • abrir o sistema noutro computador depois do prazo não mostra o arquivo
 *   • limpar dados de navegação apaga as cópias
 *
 * NADA aqui pode derrubar a conversa. Aba anônima, cota estourada, navegador
 * antigo, política corporativa: em todos esses casos as funções falham em
 * silêncio e a conversa segue funcionando — só sem a cópia local.
 */

const BANCO  = 'nfs-chat';
const LOJA   = 'anexos';
const VERSAO = 1;

/** Ao estourar a cota, quantas cópias antigas sacrificar antes de tentar de novo. */
const SACRIFICIO = 20;

export interface ArquivoLocal {
    /** id da mensagem — a chave */
    id: number;
    blob: Blob;
    nome: string;
    mime: string;
    /** quando esta máquina guardou (epoch ms) — é por aqui que a poda escolhe */
    em: number;
}

let conexao: Promise<IDBDatabase | null> | null = null;

/**
 * Abre (uma vez) o banco local. Devolve null onde IndexedDB não existe ou é
 * proibido — daí em diante todo o resto do arquivo vira no-op.
 */
function abrir(): Promise<IDBDatabase | null> {
    if (conexao) return conexao;

    conexao = new Promise<IDBDatabase | null>(resolve => {
        try {
            if (typeof indexedDB === 'undefined') return resolve(null);

            const req = indexedDB.open(BANCO, VERSAO);

            req.onupgradeneeded = () => {
                const db = req.result;

                if (!db.objectStoreNames.contains(LOJA)) {
                    const loja = db.createObjectStore(LOJA, { keyPath: 'id' });
                    // Índice por data: a poda precisa achar as mais velhas sem
                    // ler todos os blobs para memória.
                    loja.createIndex('em', 'em');
                }
            };

            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => resolve(null);
            // Firefox em janela privada nem chama onerror — só trava. O timeout
            // impede que a bolha fique esperando para sempre por uma resposta
            // que não vem.
            setTimeout(() => resolve(null), 3000);
        } catch {
            resolve(null);
        }
    });

    return conexao;
}

function transacao(db: IDBDatabase, modo: IDBTransactionMode): IDBObjectStore {
    return db.transaction(LOJA, modo).objectStore(LOJA);
}

/** Envelopa um IDBRequest numa promise que nunca rejeita. */
function pedir<T>(req: IDBRequest<T>): Promise<T | null> {
    return new Promise(resolve => {
        req.onsuccess = () => resolve(req.result);
        req.onerror   = () => resolve(null);
    });
}

// ─── API ──────────────────────────────────────────────────────────────────────

/** A cópia local deste anexo, ou null se esta máquina não tem. */
export async function buscar(id: number): Promise<ArquivoLocal | null> {
    const db = await abrir();
    if (!db) return null;

    try {
        return await pedir(transacao(db, 'readonly').get(id) as IDBRequest<ArquivoLocal>);
    } catch {
        return null;
    }
}

/**
 * Guarda a cópia. Devolve true se ficou de pé.
 *
 * Cota estourada não é erro de programa — é disco cheio de fotos antigas. Nesse
 * caso poda as mais velhas e tenta uma vez mais: a mensagem que a pessoa está
 * olhando agora vale mais que a foto de três meses atrás.
 */
export async function guardar(id: number, blob: Blob, nome: string, mime: string): Promise<boolean> {
    const db = await abrir();
    if (!db) return false;

    const registro: ArquivoLocal = { id, blob, nome, mime, em: Date.now() };

    if (await gravar(db, registro)) return true;

    await podar(db, SACRIFICIO);

    return gravar(db, registro);
}

function gravar(db: IDBDatabase, registro: ArquivoLocal): Promise<boolean> {
    return new Promise(resolve => {
        try {
            const tx = db.transaction(LOJA, 'readwrite');

            tx.oncomplete = () => resolve(true);
            tx.onerror    = () => resolve(false);
            tx.onabort    = () => resolve(false);

            tx.objectStore(LOJA).put(registro);
        } catch {
            resolve(false);
        }
    });
}

/** Apaga as `quantas` cópias mais antigas (pelo índice de data). */
async function podar(db: IDBDatabase, quantas: number): Promise<void> {
    return new Promise(resolve => {
        try {
            const tx    = db.transaction(LOJA, 'readwrite');
            const cursor = tx.objectStore(LOJA).index('em').openCursor();

            let apagadas = 0;

            tx.oncomplete = () => resolve();
            tx.onerror    = () => resolve();
            tx.onabort    = () => resolve();

            cursor.onsuccess = () => {
                const c = cursor.result;

                if (!c || apagadas >= quantas) return;

                c.delete();
                apagadas++;
                c.continue();
            };
            cursor.onerror = () => resolve();
        } catch {
            resolve();
        }
    });
}

/**
 * Guarda o que veio do servidor e devolve o blob.
 *
 * O download é o único momento em que o arquivo passa por aqui — é onde a
 * cópia local tem de nascer. Gravar em paralelo (sem await) de propósito: a
 * bolha mostra a foto assim que o blob chega, sem esperar o disco.
 */
export async function baixarEGuardar(url: string, id: number, nome: string, mime: string): Promise<Blob | null> {
    try {
        const resposta = await fetch(url, { credentials: 'same-origin' });

        if (!resposta.ok) return null;

        const blob = await resposta.blob();

        void guardar(id, blob, nome, mime);

        return blob;
    } catch {
        return null;
    }
}
