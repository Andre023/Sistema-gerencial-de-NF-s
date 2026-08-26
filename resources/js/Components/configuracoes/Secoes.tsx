import { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';
import Icone from '@/Components/painel/Icone';

/**
 * A moldura de Configurações: seletor à esquerda, seção à direita.
 *
 * Cada seção é uma página Inertia própria (Usuários, Campanha) e reusa esta
 * moldura — o que dá a sensação de aba sem manter estado nenhum no cliente.
 * Usuários mora aqui desde que saiu da navbar: eram cinco abas disputando
 * espaço com o sino, e "quem pode o quê" é configuração como as outras.
 */

type Secao = 'usuarios' | 'campanha';

interface ItemSecao {
    id: Secao;
    titulo: string;
    descricao: string;
    href: string;
    icone: string;
}

const ICONE_USUARIOS = 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z';
const ICONE_CAMPANHA = 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z';

export default function Secoes({ atual, children }: { atual: Secao; children: ReactNode }) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const secoes: ItemSecao[] = [
        {
            id: 'usuarios',
            titulo: 'Usuários',
            descricao: 'Contas e papéis',
            href: route('usuarios.index'),
            icone: ICONE_USUARIOS,
        },
        {
            id: 'campanha',
            titulo: 'Campanha de aniversário',
            descricao: 'Liga a aba e define o texto padrão',
            href: route('configuracoes.campanha'),
            icone: ICONE_CAMPANHA,
        },
    ];

    return (
        <AuthenticatedLayout header={null}>
            <div className="flex-1 w-full py-6 px-4 sm:px-6 lg:px-8 max-w-screen-xl mx-auto transition-colors duration-200"
                style={{ background: p.BG }}>

                <h1 className="text-lg font-semibold mb-5" style={{ color: p.TEXT }}>Configurações</h1>

                {/* No celular o seletor vira uma fileira que rola; no desktop,
                    coluna fixa à esquerda. */}
                <div className="flex flex-col lg:flex-row gap-5">
                    <nav className="lg:w-64 shrink-0 flex lg:flex-col gap-1.5 overflow-x-auto scrollbar-oculta lg:overflow-visible">
                        {secoes.map(secao => {
                            const ativa = secao.id === atual;

                            return (
                                <Link
                                    key={secao.id}
                                    href={secao.href}
                                    className="flex items-start gap-2.5 rounded-xl px-3 py-2.5 text-left transition shrink-0 lg:shrink"
                                    style={{
                                        background: ativa ? (isDark ? 'rgba(47,129,247,0.12)' : '#eaf2ff') : 'transparent',
                                        border: `1px solid ${ativa ? p.ACCENT + '55' : 'transparent'}`,
                                        color: ativa ? p.ACCENT : p.TEXT,
                                    }}
                                    onMouseEnter={e => { if (!ativa) e.currentTarget.style.background = p.HOVER_ROW; }}
                                    onMouseLeave={e => { if (!ativa) e.currentTarget.style.background = 'transparent'; }}
                                >
                                    <span className="mt-0.5 shrink-0" style={{ color: ativa ? p.ACCENT : p.MUTED }}>
                                        <Icone path={secao.icone} />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium whitespace-nowrap lg:whitespace-normal">
                                            {secao.titulo}
                                        </span>
                                        <span className="hidden lg:block text-xs mt-0.5" style={{ color: p.MUTED }}>
                                            {secao.descricao}
                                        </span>
                                    </span>
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="flex-1 min-w-0">{children}</div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/** Cartão padrão das seções — título, explicação e conteúdo. */
export function Cartao({ titulo, descricao, children, p }: {
    titulo: string; descricao?: string; children: ReactNode; p: Palette;
}) {
    return (
        <section className="rounded-xl p-4 sm:p-5" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            <h2 className="text-sm font-semibold" style={{ color: p.TEXT }}>{titulo}</h2>
            {descricao && <p className="text-xs mt-1 mb-4" style={{ color: p.MUTED }}>{descricao}</p>}
            {children}
        </section>
    );
}
