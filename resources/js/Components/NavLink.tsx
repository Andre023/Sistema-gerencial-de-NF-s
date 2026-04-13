import { InertiaLinkProps, Link } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';

export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}: InertiaLinkProps & { active: boolean }) {
    const { isDark } = useTheme();

    const base = 'inline-flex items-center border-b-2 px-2 pt-1 pb-0.5 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ';

    const activeClass = isDark
        ? 'border-blue-400 text-[#e6edf3]'
        : 'border-blue-500 text-gray-900';

    const inactiveClass = isDark
        ? 'border-transparent text-[#7d8590] hover:border-[#30363d] hover:text-[#e6edf3]'
        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700';

    return (
        <Link
            {...props}
            className={base + (active ? activeClass : inactiveClass) + ' ' + className}
        >
            {children}
        </Link>
    );
}
