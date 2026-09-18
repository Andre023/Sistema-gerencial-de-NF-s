import { Palette } from '@/lib/tema';

/**
 * O selo de fornecedor consignado — anda junto do NÚMERO da nota, ao lado do
 * selo CEASA e do de idade, em todas as planilhas da fila.
 *
 * Mesmo desenho do selo CEASA, em outra cor para não se confundirem. A
 * diferença está na origem: o CEASA é marcado a
 * cada nota lançada; este vem do fornecedor (Configurações › Consignados) e
 * aparece sozinho, sem ninguém marcar nada.
 *
 * Um componente só, para o selo da fila e o da lista de Configurações serem o
 * mesmo — quem marca lá reconhece o que vê aqui.
 */
export function SeloConsignado({ p, className = '' }: { p: Palette; className?: string }) {
    return (
        <span className={`inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide no-underline ${className}`}
            style={{ background: p.ORANGE + '22', color: p.ORANGE, border: `1px solid ${p.ORANGE}44` }}
            title="Fornecedor consignado">
            CONSIG.
        </span>
    );
}
