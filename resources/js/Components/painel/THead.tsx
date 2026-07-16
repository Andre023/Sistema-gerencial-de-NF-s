import { Palette } from '@/lib/tema';

export default function THead({ colunas, p }: { colunas: string[]; p: Palette }) {
    return (
        <thead>
            <tr style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                {colunas.map(c => (
                    <th key={c}
                        className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide whitespace-nowrap"
                        style={{ color: p.MUTED }}>
                        {c}
                    </th>
                ))}
            </tr>
        </thead>
    );
}
