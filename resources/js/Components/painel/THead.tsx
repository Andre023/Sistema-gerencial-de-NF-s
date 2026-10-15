import { Palette } from '@/lib/tema';
import { Ordenacao } from '@/lib/ordenacao';
import ThOrdenavel from '@/Components/painel/ThOrdenavel';

/**
 * Cabeçalho das tabelas da fila.
 *
 * `chaves` anda junto de `colunas`, posição a posição: a chave de ordenação de
 * cada coluna, ou null para a que não ordena (a de ações). Sem `ordenacao`,
 * o cabeçalho é só texto, como sempre foi.
 */
export default function THead({ colunas, p, chaves, ordenacao }: {
    colunas: string[];
    p: Palette;
    chaves?: (string | null)[];
    ordenacao?: Ordenacao;
}) {
    return (
        <thead>
            <tr style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                {colunas.map((c, i) => (
                    <ThOrdenavel key={c || i} rotulo={c} chave={chaves?.[i]} ordenacao={ordenacao} p={p}
                        className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide whitespace-nowrap" />
                ))}
            </tr>
        </thead>
    );
}
