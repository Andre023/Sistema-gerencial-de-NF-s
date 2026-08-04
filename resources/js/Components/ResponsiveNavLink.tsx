import { InertiaLinkProps, Link } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';

/**
 * Item do menu do celular. Segue o tema (claro/escuro) como o resto do painel
 * — no escuro o cinza padrão do Breeze ficava quase ilegível — e tem altura de
 * dedo (48px), não de ponteiro do mouse.
 */
export default function ResponsiveNavLink({
    active = false,
    className = '',
    children,
    ...props
}: InertiaLinkProps & { active?: boolean }) {
    const { isDark } = useTheme();

    const cores = active
        ? isDark
            ? 'border-blue-400 bg-blue-500/10 text-blue-300'
            : 'border-blue-500 bg-blue-50 text-blue-700'
        : isDark
            ? 'border-transparent text-[#e6edf3] hover:border-[#30363d] hover:bg-[#21262d]'
            : 'border-transparent text-gray-700 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900';

    return (
        <Link
            {...props}
            className={`flex w-full items-center border-l-4 py-3 pe-4 ps-3 min-h-[48px] ${cores} text-base font-medium transition duration-150 ease-in-out focus:outline-none ${className}`}
        >
            {children}
        </Link>
    );
}
