import { useCallback, useEffect, useRef, useState } from 'react';
import { Avatar } from '@/types';
import { Folha, Op, aplicarOps, inversa, normalizarFolha } from '@/lib/cotacao';

/**
 * As folhas da cotação, sincronizadas com os outros compradores.
 *
 * ── Como duas telas ficam iguais ──────────────────────────────────────────────
 * Cada folha tem uma `versao` no servidor, que sobe 1 a cada leva de
 * alterações gravada. A tela guarda:
 *
 *   • `base`  — a folha como o servidor tinha na versão `versao`;
 *   • `fila`  — levas que já chegaram mas ainda não dá para aplicar, porque
 *               falta uma versão antes delas (o WebSocket não garante ordem
 *               entre o aviso de um colega e a resposta do meu próprio envio);
 *   • `voo`   — a leva minha que está indo para o servidor;
 *   • `espera`— o que eu fiz enquanto ela ia.
 *
 * O que aparece na tela é a base com tudo isso aplicado por cima, nessa ordem.
 * Quando a leva de um colega chega, ela entra na base ANTES das minhas que
 * ainda não voltaram — que é exatamente a ordem em que o servidor vai gravar.
 * Se dois escreverem na mesma célula, as duas telas terminam com o valor de
 * quem chegou por último no servidor.
 *
 * Se uma versão some (o aviso se perdeu), em poucos segundos a tela desiste de
 * esperar e busca a folha inteira.
 */

export interface Presente {
    id: number;
    name: string;
    avatar?: Avatar | null;
}

export interface Foco {
    id: number;
    nome: string;
    folha: number;
    l: string;
    c: string;
}

interface Estado {
    base: Folha;
    versao: number;
    fila: Map<number, Op[]>;
    voo: Op[] | null;
    espera: Op[];
    desfazer: Op[][];
    timerDoBuraco?: ReturnType<typeof setTimeout>;
}

interface Aviso {
    folha: number;
    versao: number;
    cliente: string;
    ops?: Op[];
    recarregar?: boolean;
    removida?: boolean;
}

/** Esta aba do navegador. É como a tela reconhece o eco das próprias alterações. */
const CLIENTE = Math.random().toString(36).slice(2, 12);

const LIMITE_DO_DESFAZER = 100;

export function useCotacaoAoVivo(cotacaoId: number, iniciais: Folha[], meuId: number) {
    const estados = useRef(new Map<number, Estado>());
    const [folhas, setFolhas] = useState<Folha[]>(() => {
        const lista = iniciais.map(normalizarFolha);
        for (const f of lista) estados.current.set(f.id, novoEstado(f));
        return lista;
    });
    const [erro, setErro] = useState('');
    const [salvando, setSalvando] = useState(false);
    /** O servidor não respondeu: as alterações estão guardadas aqui, esperando. */
    const [semConexao, setSemConexao] = useState(false);
    const [presentes, setPresentes] = useState<Presente[]>([]);
    const [focos, setFocos] = useState<Foco[]>([]);
    const canalRef = useRef<any>(null);

    // ── O que a tela mostra ──

    const exibir = useCallback((id: number) => {
        const st = estados.current.get(id);
        setFolhas(atual => {
            if (!st) return atual.filter(f => f.id !== id);
            const naFila = [...st.fila.entries()].sort((a, b) => a[0] - b[0]).flatMap(([, ops]) => ops);
            const folha = aplicarOps(st.base, [...naFila, ...(st.voo ?? []), ...st.espera]);
            const i = atual.findIndex(f => f.id === id);
            if (i < 0) return [...atual, folha];
            const nova = atual.slice();
            nova[i] = folha;
            return nova;
        });
        setSalvando([...estados.current.values()].some(s => s.voo || s.espera.length));
    }, []);

    // ── Do servidor para a tela ──

    const recarregar = useCallback(async (id: number) => {
        try {
            const { data } = await window.axios.get(route('cotacao.folhas.mostrar', [cotacaoId, id]));
            const folha = normalizarFolha(data.folha);
            const st = estados.current.get(id);

            if (!st) {
                estados.current.set(id, novoEstado(folha));
            } else {
                st.base = folha;
                st.versao = folha.versao;
                for (const v of [...st.fila.keys()]) if (v <= folha.versao) st.fila.delete(v);
                drenar(st);
            }
            exibir(id);
        } catch (e: any) {
            // 404: outro comprador apagou a folha enquanto ela vinha.
            if (e?.response?.status === 404) {
                estados.current.delete(id);
                exibir(id);
            }
        }
    }, [cotacaoId, exibir]);

    const drenar = (st: Estado) => {
        while (st.fila.has(st.versao + 1)) {
            st.base = aplicarOps(st.base, st.fila.get(st.versao + 1)!);
            st.versao++;
            st.fila.delete(st.versao);
        }

        clearTimeout(st.timerDoBuraco);
        if (st.fila.size) {
            const id = st.base.id;
            st.timerDoBuraco = setTimeout(() => {
                if (estados.current.get(id)?.fila.size) recarregar(id);
            }, 3000);
        }
    };

    const receber = useCallback((aviso: Aviso) => {
        if (aviso.cliente === CLIENTE) return;

        const st = estados.current.get(aviso.folha);

        if (aviso.removida) {
            estados.current.delete(aviso.folha);
            exibir(aviso.folha);
            return;
        }

        if (!st || aviso.recarregar || !aviso.ops) {
            recarregar(aviso.folha);
            return;
        }

        if (aviso.versao <= st.versao) return;
        st.fila.set(aviso.versao, aviso.ops);
        drenar(st);
        exibir(aviso.folha);
    }, [exibir, recarregar]);

    // ── Da tela para o servidor ──

    const despachar = useCallback(async (id: number) => {
        const st = estados.current.get(id);
        if (!st || st.voo || !st.espera.length) return;

        st.voo = st.espera;
        st.espera = [];

        try {
            const { data } = await window.axios.patch(route('cotacao.folhas.alterar', [cotacaoId, id]), { ops: st.voo, cliente: CLIENTE });
            if (data.versao > st.versao) st.fila.set(data.versao, st.voo);
            st.voo = null;
            setSemConexao(false);
            drenar(st);
            exibir(id);
            despachar(id);
        } catch (e: any) {
            const status: number | undefined = e?.response?.status;

            // Sem resposta (Wi-Fi caiu, servidor reiniciando) ou erro do lado
            // de lá: a leva NÃO se perde. Volta para a frente da fila e a tela
            // tenta de novo sozinha — o comprador continua digitando, e tudo
            // sobe junto quando a conexão voltar.
            if (status === undefined || status >= 500 || status === 429) {
                st.espera = [...(st.voo ?? []), ...st.espera];
                st.voo = null;
                setSemConexao(true);
                exibir(id);
                clearTimeout(timers.current.get(id));
                timers.current.set(id, setTimeout(() => despachar(id), 4000));
                return;
            }

            // Sessão vencida: tentar de novo não adianta, e recarregar a folha
            // apagaria da tela o que a pessoa fez. Avisa e deixa como está.
            if (status === 401 || status === 419) {
                st.espera = [...(st.voo ?? []), ...st.espera];
                st.voo = null;
                setErro('Sua sessão expirou. Entre de novo em outra aba e volte aqui — as alterações desta tela ainda não foram salvas.');
                exibir(id);
                return;
            }

            // O servidor recusou a leva: o que veio depois dela foi feito em
            // cima de algo que não existe mais. Descarta e volta ao que vale.
            st.voo = null;
            st.espera = [];
            setErro(e?.response?.data?.message ?? 'Não consegui salvar a última alteração. A folha foi recarregada.');
            recarregar(id);
        }
    }, [cotacaoId, exibir, recarregar]);

    const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>());

    /** Aplica na hora e manda ao servidor (juntando o que vier em seguida). */
    const enviar = useCallback((id: number, ops: Op[], desfazivel = true) => {
        const st = estados.current.get(id);
        if (!st || !ops.length) return;

        if (desfazivel) {
            const atual = folhaExibida(st);
            st.desfazer.push(inversa(atual, ops));
            if (st.desfazer.length > LIMITE_DO_DESFAZER) st.desfazer.shift();
        }

        st.espera.push(...ops);
        exibir(id);

        // Um respiro curto para juntar a digitação rápida numa leva só.
        clearTimeout(timers.current.get(id));
        timers.current.set(id, setTimeout(() => despachar(id), 120));
    }, [despachar, exibir]);

    const desfazer = useCallback((id: number) => {
        const st = estados.current.get(id);
        const ops = st?.desfazer.pop();
        if (ops?.length) enviar(id, ops, false);
    }, [enviar]);

    /** Folhas que esta tela criou ou gerou (a resposta do servidor já as traz). */
    const adotar = useCallback((novas: Folha[]) => {
        for (const bruta of novas) {
            const f = normalizarFolha(bruta);
            const st = estados.current.get(f.id);
            if (st) {
                st.base = f;
                st.versao = f.versao;
                st.fila.clear();
                st.desfazer = [];
            } else {
                estados.current.set(f.id, novoEstado(f));
            }
            exibir(f.id);
        }
    }, [exibir]);

    const esquecer = useCallback((id: number) => {
        estados.current.delete(id);
        exibir(id);
    }, [exibir]);

    // ── Canal: alterações, quem está aqui e onde cada um está ──

    useEffect(() => {
        const nome = `cotacao.${cotacaoId}`;
        const canal = window.Echo.join(nome)
            .here((pessoas: Presente[]) => setPresentes(pessoas))
            .joining((p: Presente) => setPresentes(atual => [...atual.filter(x => x.id !== p.id), p]))
            .leaving((p: Presente) => {
                setPresentes(atual => atual.filter(x => x.id !== p.id));
                setFocos(atual => atual.filter(f => f.id !== p.id));
            })
            .listen('.CotacaoAlterada', receber)
            .listenForWhisper('foco', (f: Foco) => {
                setFocos(atual => [...atual.filter(x => x.id !== f.id), f]);
            });

        canalRef.current = canal;

        return () => {
            canalRef.current = null;
            window.Echo.leave(nome);
        };
    }, [cotacaoId, receber]);

    // Whisper: vai de navegador a navegador pelo Reverb, sem passar pelo PHP.
    // Mesmo assim só um por 150 ms — quem segura a seta não precisa mandar 30.
    const ultimoFoco = useRef<{ quando: number; timer?: ReturnType<typeof setTimeout> }>({ quando: 0 });
    const avisarFoco = useCallback((folha: number, l: string, c: string, nome: string) => {
        const mandar = () => {
            ultimoFoco.current.quando = Date.now();
            canalRef.current?.whisper('foco', { id: meuId, nome, folha, l, c });
        };
        clearTimeout(ultimoFoco.current.timer);
        const falta = 150 - (Date.now() - ultimoFoco.current.quando);
        if (falta <= 0) mandar(); else ultimoFoco.current.timer = setTimeout(mandar, falta);
    }, [meuId]);

    // Antes de fechar a aba com alteração indo: o navegador pergunta.
    useEffect(() => {
        const antes = (e: BeforeUnloadEvent) => {
            if ([...estados.current.values()].some(s => s.voo || s.espera.length)) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', antes);
        return () => window.removeEventListener('beforeunload', antes);
    }, []);

    const podeDesfazer = (id: number) => !!estados.current.get(id)?.desfazer.length;

    return {
        folhas, enviar, desfazer, podeDesfazer, adotar, esquecer, recarregar,
        presentes: presentes.filter(p => p.id !== meuId),
        focos: focos.filter(f => f.id !== meuId && presentes.some(p => p.id === f.id)),
        avisarFoco, salvando, semConexao, erro, limparErro: () => setErro(''), cliente: CLIENTE,
    };
}

function novoEstado(f: Folha): Estado {
    return { base: f, versao: f.versao, fila: new Map(), voo: null, espera: [], desfazer: [] };
}

function folhaExibida(st: Estado): Folha {
    const naFila = [...st.fila.entries()].sort((a, b) => a[0] - b[0]).flatMap(([, ops]) => ops);
    return aplicarOps(st.base, [...naFila, ...(st.voo ?? []), ...st.espera]);
}
