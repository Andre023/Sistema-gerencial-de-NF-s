import { Fornecedor } from '@/types';
import { Palette } from '@/lib/tema';

/**
 * As marcas de fornecedor — consignado, feira, uso e consumo.
 *
 * Espelho de Fornecedor::MARCAS no backend: o slug é a URL da seção em
 * Configurações e `campo` é a coluna que chega dentro de `nota.fornecedor`.
 * O que a marca FAZ é uma coisa só — pôr um selo junto do número em toda nota
 * do fornecedor — e o texto e a cor do selo moram aqui, que é quem desenha.
 *
 * A ordem desta lista é a ordem dos selos na fila e das seções no seletor.
 */
export interface Marca {
    slug: MarcaSlug;
    campo: 'consignado' | 'feira' | 'uso_consumo';
    titulo: string;
    /** O texto curto do selo. */
    selo: string;
    /** A frase da seção (o que o selo quer dizer). */
    descricao: string;
    /** Chave da paleta: cada marca tem a sua, para não se confundirem. */
    cor: keyof Pick<Palette, 'ORANGE' | 'TEAL' | 'PINK'>;
}

export type MarcaSlug = 'consignados' | 'feira' | 'uso-consumo';

export const MARCAS: Marca[] = [
    {
        slug: 'consignados', campo: 'consignado', titulo: 'Consignados', selo: 'CONSIG.',
        descricao: 'Fornecedores que trabalham em consignação.', cor: 'ORANGE',
    },
    {
        slug: 'feira', campo: 'feira', titulo: 'Feira', selo: 'FEIRA',
        descricao: 'Fornecedores de feira.', cor: 'TEAL',
    },
    {
        slug: 'uso-consumo', campo: 'uso_consumo', titulo: 'Uso e consumo', selo: 'USO/CONS.',
        descricao: 'Fornecedores de material de uso e consumo (não é mercadoria para venda).', cor: 'PINK',
    },
];

export const marcaPorSlug = (slug: MarcaSlug): Marca => MARCAS.find(m => m.slug === slug)!;

/** As marcas que este fornecedor tem, na ordem da lista. */
export const marcasDe = (f: Fornecedor): Marca[] => MARCAS.filter(m => !!f[m.campo]);
