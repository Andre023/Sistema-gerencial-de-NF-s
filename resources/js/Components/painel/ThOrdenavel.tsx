import { CSSProperties, ReactNode } from 'react';
import { Palette } from '@/lib/tema';
import { Ordenacao } from '@/lib/ordenacao';

/** Só usa duas cores — as páginas com paleta própria (Dossiê, Estatísticas) também servem. */
type Cores = Pick<Palette, 'MUTED' | 'ACCENT'>;

/**
 * O nome da coluna como botão de ordenar, com a setinha: apagada enquanto a
 * coluna não ordena — para dar a entender que dá para clicar — e acesa,
 * apontando o sentido, quando ordena.
 *
 * Separado do <th> porque nem toda lista é <table>: a de fornecedores em
 * Configurações é feita de linhas soltas e usa o botão direto.
 */
export function BotaoOrdenar({ rotulo, chave, ordenacao, p, alinhar = 'left' }: {
    rotulo: ReactNode; chave: string; ordenacao: Ordenacao; p: Cores; alinhar?: 'left' | 'right';
}) {
    const ativa = ordenacao.ordem?.chave === chave ? ordenacao.ordem.dir : null;
    const nome = typeof rotulo === 'string' ? rotulo.toLowerCase() : 'esta coluna';

    return (
        <button type="button" onClick={() => ordenacao.alternar(chave)}
            title={ativa === 'asc'
                ? `Ordenado por ${nome} (crescente) — clique para inverter`
                : ativa === 'desc'
                    ? `Ordenado por ${nome} (decrescente) — clique para voltar à ordem normal`
                    : `Ordenar por ${nome}`}
            className={`group/ord inline-flex items-center gap-1 [text-transform:inherit] [letter-spacing:inherit] [font-weight:inherit] transition-colors ${alinhar === 'right' ? 'flex-row-reverse' : ''}`}
            style={{ color: ativa ? p.ACCENT : 'inherit' }}>
            <span>{rotulo}</span>
            <svg className={`w-3 h-3 shrink-0 transition ${ativa ? '' : 'opacity-30 group-hover/ord:opacity-80'}`}
                fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                {ativa === 'asc' && <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 15l7-7 7 7" />}
                {ativa === 'desc' && <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />}
                {!ativa && <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 9l4-4 4 4M16 15l-4 4-4-4" />}
            </svg>
        </button>
    );
}

/**
 * Um <th> que ordena a tabela ao clicar no nome (lib/ordenacao.ts).
 * Sem `chave` (a coluna de ações, por exemplo) é um <th> comum.
 */
export default function ThOrdenavel({ rotulo, chave, ordenacao, p, className = '', style, alinhar = 'left' }: {
    rotulo: ReactNode;
    chave?: string | null;
    ordenacao?: Ordenacao;
    p: Cores;
    className?: string;
    style?: CSSProperties;
    alinhar?: 'left' | 'right';
}) {
    const estilo = { color: p.MUTED, ...style };

    if (!chave || !ordenacao) {
        return <th className={className} style={estilo}>{rotulo}</th>;
    }

    const ativa = ordenacao.ordem?.chave === chave ? ordenacao.ordem.dir : null;

    return (
        <th className={className} style={estilo}
            aria-sort={ativa === 'asc' ? 'ascending' : ativa === 'desc' ? 'descending' : 'none'}>
            <BotaoOrdenar rotulo={rotulo} chave={chave} ordenacao={ordenacao} p={p} alinhar={alinhar} />
        </th>
    );
}
