import { Card } from '@/types';
import { CARD_COR_DARK, CARD_COR_LIGHT, TIPO_CARD_LABEL } from '@/lib/tema';

/**
 * Pílula de um card de divergência.
 *  - aberto:    cor cheia (está travando a nota)
 *  - corrigido: borda tracejada + ✓ (compras corrigiu; pré-lote precisa reconferir)
 *  - resolvido: apagada e riscada (histórico, só no modal de detalhe)
 */
export default function CardBadge({ card, isDark }: { card: Card; isDark: boolean }) {
    const map = isDark ? CARD_COR_DARK : CARD_COR_LIGHT;
    const c = map[card.tipo] ?? { bg: 'transparent', text: isDark ? '#7d8590' : '#6b7280', border: isDark ? '#30363d' : '#e5e7eb' };

    const resolvido = card.status === 'resolvido';
    const corrigido = card.status === 'corrigido';

    return (
        <span className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap"
            title={
                (card.detalhe ? `${card.detalhe} — ` : '') +
                (resolvido ? 'resolvido' : corrigido ? 'corrigido, aguardando reconferência do pré-lote' : 'em aberto') +
                (card.reaberturas > 0 ? ` (${card.reaberturas}x reaberto)` : '')
            }
            style={{
                background: resolvido ? 'transparent' : c.bg,
                color: c.text,
                border: `1px ${corrigido ? 'dashed' : 'solid'} ${c.border}`,
                opacity: resolvido ? 0.55 : 1,
                textDecoration: resolvido ? 'line-through' : 'none',
            }}>
            {TIPO_CARD_LABEL[card.tipo] ?? card.tipo}
            {corrigido && <span aria-label="corrigido, aguardando reconferência">✓?</span>}
            {resolvido && <span aria-label="resolvido">✓</span>}
        </span>
    );
}
