import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import OnlineSidebar from '@/Components/OnlineSidebar';
import SinoNotificacoes from '@/Components/painel/SinoNotificacoes';
import NotificacoesProvider from '@/Components/painel/NotificacoesProvider';
import ChatProvider from '@/Components/chat/ChatProvider';
import AtalhosDaFila from '@/Components/painel/AtalhosDaFila';
import Avatar from '@/Components/painel/Avatar';
import { Link, usePage, router } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useState, useEffect } from 'react';
import { User, Permissoes } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';

interface FlashMessage {
    sucesso?: string;
    erro?: string;
}

// ── Ícone Sol ──────────────────────────────────────────────────────────────────
function IconeSol() {
    return (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
        </svg>
    );
}

// ── Ícone Lua ──────────────────────────────────────────────────────────────────
function IconeLua() {
    return (
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
        </svg>
    );
}

export default function AuthenticatedLayout({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth, flash } = usePage().props as { auth: { user: User; can: Permissoes }; flash?: FlashMessage };
    const user = auth.user;
    const can = auth.can;
    const { isDark, toggleTheme } = useTheme();

    const [showingNavDropdown, setShowingNavDropdown] = useState(false);
    const [flashMsg, setFlashMsg] = useState<FlashMessage | null>(null);

    useEffect(() => {
        if (flash?.sucesso || flash?.erro) {
            setFlashMsg(flash);
            const t = setTimeout(() => setFlashMsg(null), 4000);
            return () => clearTimeout(t);
        }
    }, [flash]);

    // Trocou de tela pelo menu do celular: fecha o menu, senão ele fica aberto
    // por cima da página que acabou de abrir.
    useEffect(() => router.on('navigate', () => setShowingNavDropdown(false)), []);

    // ── Tokens de cor conforme tema
    const navBg     = isDark ? 'bg-[#161b22]'   : 'bg-white';
    const navBorder = isDark ? 'border-[#21262d]' : 'border-gray-100';
    const pageBg    = isDark ? 'bg-[#0d1117]'   : 'bg-gray-100';

    return (
        <NotificacoesProvider userId={user.id}>
        {/* O chat mora dentro do provedor do sino porque divide o mesmo canal
            privado (`usuario.{id}`) — ver ChatProvider. */}
        <ChatProvider userId={user.id}>
        <div className={`min-h-screen flex flex-col transition-colors duration-200 ${pageBg}`}>

            {/* ── NAVBAR ── */}
            <nav className={`border-b ${navBorder} ${navBg} shadow-sm sticky top-0 z-40 transition-colors duration-200`}>
                <div className="mx-auto max-w-screen-2xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between items-center gap-2">

                        {/* Logo + Links — os links completos só cabem a partir de
                            1024px (5 abas + e-mail + usuário); abaixo disso o menu
                            vira a sanfona do celular. */}
                        <div className="flex items-center gap-6 xl:gap-10 min-w-0">
                            <Link href="/" className="flex shrink-0 items-center">
                                <ApplicationLogo className={`block h-8 w-auto fill-current ${isDark ? 'text-blue-400' : 'text-blue-700'}`} />
                            </Link>

                            <div className="hidden lg:flex gap-2">
                                <NavLink href={route('notas.index')} active={route().current('notas.*')}>
                                    Notas
                                </NavLink>
                                {can.verEstatisticas && (
                                    <NavLink href={route('estatisticas.index')} active={route().current('estatisticas.*')}>
                                        Estatísticas
                                    </NavLink>
                                )}
                                {can.verDossie && (
                                    <NavLink href={route('dossie.index')} active={route().current('dossie.*')}>
                                        Fornecedores
                                    </NavLink>
                                )}
                                {can.gerenciarPrioridades && (
                                    <NavLink href={route('prioridades.index')} active={route().current('prioridades.*')}>
                                        Prioridades
                                    </NavLink>
                                )}
                                {can.gerenciarUsuarios && (
                                    <NavLink href={route('usuarios.index')} active={route().current('usuarios.*')}>
                                        Usuários
                                    </NavLink>
                                )}
                            </div>
                        </div>

                        {/* Direita: sino + toggle + usuário */}
                        <div className="hidden lg:flex items-center gap-3 shrink-0">

                            <SinoNotificacoes />

                            {/* Botão Dark/Light */}
                            <button
                                onClick={toggleTheme}
                                title={isDark ? 'Mudar para modo claro' : 'Mudar para modo escuro'}
                                className={`
                                    flex items-center justify-center w-9 h-9 rounded-lg transition-all duration-200
                                    ${isDark
                                        ? 'bg-[#21262d] text-yellow-400 hover:bg-[#30363d]'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                    }
                                `}
                            >
                                {isDark ? <IconeSol /> : <IconeLua />}
                            </button>

                            {/* O e-mail é o primeiro a sair quando o espaço aperta:
                                o nome já aparece no botão ao lado. */}
                            <span className={`hidden xl:block text-sm truncate max-w-[220px] ${isDark ? 'text-[#7d8590]' : 'text-gray-500'}`}>
                                {user.email}
                            </span>

                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        className="flex items-center gap-2 rounded-full bg-blue-600 text-white px-3 py-1.5 text-sm font-medium hover:bg-blue-700 transition"
                                    >
                                        <Avatar user={user} size={24} ring="rgba(255,255,255,0.9)" />
                                        {user.name.split(' ')[0]}
                                        <svg className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                            <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                        </svg>
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content contentClasses={isDark ? 'py-1 bg-[#161b22]' : 'py-1 bg-white'}>
                                    <Dropdown.Link href={route('profile.edit')}>Perfil</Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">
                                        Sair
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>

                        {/* Mobile menu button */}
                        <div className="lg:hidden flex items-center gap-1.5 shrink-0">
                            <SinoNotificacoes />
                            <button
                                onClick={toggleTheme}
                                aria-label={isDark ? 'Mudar para modo claro' : 'Mudar para modo escuro'}
                                className={`flex items-center justify-center w-10 h-10 rounded-lg transition ${isDark ? 'bg-[#21262d] text-yellow-400' : 'bg-gray-100 text-gray-600'}`}
                            >
                                {isDark ? <IconeSol /> : <IconeLua />}
                            </button>
                            <button
                                onClick={() => setShowingNavDropdown(s => !s)}
                                aria-label="Abrir menu"
                                aria-expanded={showingNavDropdown}
                                className={`inline-flex items-center justify-center rounded-md p-2.5 transition ${isDark ? 'text-[#7d8590] hover:bg-[#21262d]' : 'text-gray-400 hover:bg-gray-100'}`}
                            >
                                <svg className="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    {showingNavDropdown
                                        ? <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                        : <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />}
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                {/* Mobile menu — com altura máxima e rolagem própria: em tela
                    baixa (celular deitado) a lista não cabia e o "Sair" ficava
                    fora do alcance. */}
                <div className={`${showingNavDropdown ? 'block' : 'hidden'} lg:hidden border-t ${navBorder} max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain`}>
                    <div className="space-y-1 px-4 pb-3 pt-2">
                        <ResponsiveNavLink href={route('notas.index')} active={route().current('notas.*')}>
                            Notas
                        </ResponsiveNavLink>
                        {can.verEstatisticas && (
                            <ResponsiveNavLink href={route('estatisticas.index')} active={route().current('estatisticas.*')}>
                                Estatísticas
                            </ResponsiveNavLink>
                        )}
                        {can.verDossie && (
                            <ResponsiveNavLink href={route('dossie.index')} active={route().current('dossie.*')}>
                                Fornecedores
                            </ResponsiveNavLink>
                        )}
                        {can.gerenciarPrioridades && (
                            <ResponsiveNavLink href={route('prioridades.index')} active={route().current('prioridades.*')}>
                                Prioridades
                            </ResponsiveNavLink>
                        )}
                        {can.gerenciarUsuarios && (
                            <ResponsiveNavLink href={route('usuarios.index')} active={route().current('usuarios.*')}>
                                Usuários
                            </ResponsiveNavLink>
                        )}
                    </div>
                    <div className={`border-t ${navBorder} pb-3 pt-4 px-4`}>
                        <div className={`text-base font-medium ${isDark ? 'text-[#e6edf3]' : 'text-gray-800'}`}>{user.name}</div>
                        <div className={`text-sm ${isDark ? 'text-[#7d8590]' : 'text-gray-500'}`}>{user.email}</div>
                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>Perfil</ResponsiveNavLink>
                            <ResponsiveNavLink method="post" href={route('logout')} as="button">Sair</ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {/* ── ATALHOS DAS PLANILHAS ──
                Só na fila: são âncoras desta página, não links para outras. */}
            {route().current('notas.*') && (
                <AtalhosDaFila podeVerDevolucoes={!!can.usarDevolucoes} />
            )}

            {/* ── FLASH ── */}
            {flashMsg && (
                <div className={`fixed top-20 right-4 left-4 sm:left-auto sm:max-w-sm z-50 rounded-lg px-4 py-3 shadow-lg text-sm font-medium transition-all ${
                    flashMsg.sucesso
                        ? 'bg-green-50 border border-green-200 text-green-800'
                        : 'bg-red-50 border border-red-200 text-red-800'
                }`}>
                    {flashMsg.sucesso ?? flashMsg.erro}
                </div>
            )}

            {/* ── CONTEÚDO + SIDEBAR ──
                Header e main ficam numa COLUNA, e a barra de online fica ao lado
                dela ocupando toda a altura (começa logo abaixo da navbar). Assim
                o `h-[calc(100vh-4rem)]` da barra bate certo mesmo com header na
                tela — antes o header empurrava a barra pra baixo e o fim dela
                era cortado em telas menores. */}
            <div className="flex flex-1 relative">
                <div className="flex-1 min-w-0 flex flex-col">
                    {header && (
                        <header className={`${isDark ? 'bg-[#161b22] border-b border-[#21262d]' : 'bg-white shadow-sm'}`}>
                            <div className="mx-auto max-w-screen-2xl px-4 py-5 sm:px-6 lg:px-8">
                                {header}
                            </div>
                        </header>
                    )}
                    {/* `flex flex-col` aqui + `flex-1` no conteúdo da página: o
                        fundo preenche a tela sem precisar de `min-h-screen` na
                        página. Com `min-h-screen` a página media 100vh DENTRO de
                        uma área que já era 100vh−navbar, e sobrava sempre uma
                        rolagem de 64px que não levava a lugar nenhum. */}
                    <main className="flex-1 min-w-0 overflow-auto flex flex-col">
                        {children}
                    </main>
                </div>
                <OnlineSidebar currentUserId={user.id} />
            </div>
        </div>
        </ChatProvider>
        </NotificacoesProvider>
    );
}
