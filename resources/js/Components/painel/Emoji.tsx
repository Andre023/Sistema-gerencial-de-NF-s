import { emojiUrl } from '@/lib/emoji';

interface Props {
    emoji: string;
    /** Lado da imagem em px. */
    size?: number;
    className?: string;
    title?: string;
}

/**
 * Emoji como imagem (conjunto Noto) — desenho igual em Windows 10, 11, celular
 * etc. Se o emoji nao estiver no pacote (assets/emoji/), cai no texto nativo do
 * SO em vez de sumir — assim nada quebra se um dia salvarem um emoji fora da lista.
 */
export default function Emoji({ emoji, size = 20, className = '', title }: Props) {
    const url = emojiUrl(emoji);
    if (!url) {
        return (
            <span className={className} title={title} style={{ fontSize: size, lineHeight: 1 }}>
                {emoji}
            </span>
        );
    }
    return (
        <img
            src={url}
            alt={emoji}
            title={title}
            draggable={false}
            className={className}
            style={{ width: size, height: size, display: 'inline-block', verticalAlign: '-0.15em' }}
        />
    );
}
