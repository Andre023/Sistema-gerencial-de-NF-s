import { User, Avatar as AvatarTipo } from '@/types';
import { corDoNome, iniciais } from '@/lib/avatares';

interface Props {
    /** Basta name + avatar — funciona tanto com User completo quanto com o
     *  {id, name, avatar} da reserva (visualizando_por). */
    user: { name: string; avatar?: AvatarTipo | null };
    size?: number;
    /** Cor do anel (borda) — passe o fundo onde o avatar aparece, se quiser recorte. */
    ring?: string;
    className?: string;
    title?: string;
}

/**
 * Avatar único do sistema. Resolve na ordem emoji → monograma. Quando a pessoa
 * não escolheu nada (ou a relação veio sem as colunas), cai no monograma com a
 * cor derivada do nome — todo mundo sempre tem um avatar coerente.
 */
export default function Avatar({ user, size = 28, ring, className = '', title }: Props) {
    const av = user.avatar;
    const dim = { width: size, height: size };
    const anel = ring ? { boxShadow: `0 0 0 2px ${ring}` } : {};
    const titulo = title ?? user.name;

    if (av?.tipo === 'emoji' && av.valor) {
        return (
            <span title={titulo}
                className={`inline-flex items-center justify-center rounded-full shrink-0 select-none ${className}`}
                style={{ ...dim, ...anel, fontSize: Math.round(size * 0.62), lineHeight: 1 }}>
                {av.valor}
            </span>
        );
    }

    const cor = (av?.tipo === 'monograma' && av.valor) ? av.valor : corDoNome(user.name);
    return (
        <span title={titulo}
            className={`inline-flex items-center justify-center rounded-full shrink-0 text-white font-bold select-none ${className}`}
            style={{ ...dim, ...anel, background: cor, fontSize: Math.round(size * 0.4) }}>
            {iniciais(user.name)}
        </span>
    );
}
