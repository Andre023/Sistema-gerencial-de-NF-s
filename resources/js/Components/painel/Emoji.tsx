import { useState } from 'react';
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
 * etc. Se o SVG nao existir em public/emoji/ (emoji salvo antes de o pacote
 * mudar), cai no emoji nativo do SO em vez de sumir.
 */
export default function Emoji({ emoji, size = 20, className = '', title }: Props) {
    // Guardamos QUAL emoji falhou, e nao um booleano: o mesmo componente e
    // reaproveitado para outro emoji (a grade do picker), e um "falhou = true"
    // grudado esconderia a imagem seguinte, que existe.
    const [falhou, setFalhou] = useState<string | null>(null);

    if (falhou === emoji) {
        return (
            <span className={className} title={title} style={{ fontSize: size, lineHeight: 1 }}>
                {emoji}
            </span>
        );
    }

    return (
        <img
            src={emojiUrl(emoji)}
            alt={emoji}
            title={title}
            draggable={false}
            onError={() => setFalhou(emoji)}
            className={className}
            style={{ width: size, height: size, display: 'inline-block', verticalAlign: '-0.15em' }}
        />
    );
}
