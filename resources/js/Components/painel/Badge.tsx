import { MOTIVO_COR_DARK, MOTIVO_COR_LIGHT } from '@/lib/tema';

export default function Badge({ label, isDark }: { label: string; isDark: boolean }) {
    const map = isDark ? MOTIVO_COR_DARK : MOTIVO_COR_LIGHT;
    const c = map[label] ?? {
        bg: isDark ? '#21262d' : '#f3f4f6',
        text: isDark ? '#7d8590' : '#6b7280',
        border: isDark ? '#30363d' : '#e5e7eb',
    };
    return (
        <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
            style={{ background: c.bg, color: c.text, border: `1px solid ${c.border}` }}>
            {label}
        </span>
    );
}
