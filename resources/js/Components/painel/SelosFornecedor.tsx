import { Fornecedor } from '@/types';
import { Palette } from '@/lib/tema';
import { Marca, marcasDe } from '@/lib/marcas';

/**
 * Um selo de marca de fornecedor (CONSIG., FEIRA, USO/CONS.).
 *
 * Mesmo desenho do selo CEASA, cada marca na sua cor para não se confundirem.
 * A diferença está na origem: o CEASA é marcado a cada nota lançada; estes
 * vêm do fornecedor (Configurações) e aparecem sozinhos, sem ninguém marcar.
 *
 * Um componente só, para o selo da fila e o da lista de Configurações serem o
 * mesmo — quem marca lá reconhece o que vê aqui.
 */
export function SeloMarca({ marca, p, className = '' }: { marca: Marca; p: Palette; className?: string }) {
    const cor = p[marca.cor];

    return (
        <span className={`inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide no-underline ${className}`}
            style={{ background: cor + '22', color: cor, border: `1px solid ${cor}44` }}
            title={`Fornecedor ${marca.titulo.toLowerCase()}`}>
            {marca.selo}
        </span>
    );
}

/**
 * Os selos de um fornecedor, na ordem das marcas — vão junto do NÚMERO da
 * nota, ao lado do selo CEASA e do de idade, em todas as planilhas da fila.
 * Um fornecedor pode ter mais de uma marca; então saem lado a lado.
 */
export default function SelosFornecedor({ fornecedor, p, className = '' }: {
    fornecedor: Fornecedor; p: Palette; className?: string;
}) {
    return (
        <>
            {marcasDe(fornecedor).map(m => <SeloMarca key={m.slug} marca={m} p={p} className={className} />)}
        </>
    );
}
