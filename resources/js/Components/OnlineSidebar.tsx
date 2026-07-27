import { useState, useEffect } from 'react';
import { useTheme } from '@/Contexts/ThemeContext';
import { Avatar as AvatarTipo } from '@/types';
import Avatar from '@/Components/painel/Avatar';

interface UsuarioOnline { id: number; name: string; avatar?: AvatarTipo | null }
interface Props { currentUserId: number }

export default function OnlineSidebar({ currentUserId }: Props) {
    const [expandida, setExpandida] = useState(false);
    const [usuariosOnline, setUsuariosOnline] = useState<UsuarioOnline[]>([]);
    const { isDark } = useTheme();

    useEffect(() => {
        window.Echo.join('presenca.sistema')
            .here((users: UsuarioOnline[]) => setUsuariosOnline(users))
            .joining((user: UsuarioOnline) => setUsuariosOnline(prev => [...prev, user]))
            .leaving((user: UsuarioOnline) => setUsuariosOnline(prev => prev.filter(u => u.id !== user.id)))
            .error((error: any) => console.error('Erro no Reverb:', error));

        return () => { window.Echo.leave('presenca.sistema'); };
    }, []);

    const bg     = isDark ? 'bg-[#161b22]' : 'bg-white';
    const border = isDark ? 'border-[#21262d]' : 'border-gray-200';
    const textMuted  = isDark ? 'text-[#7d8590]' : 'text-gray-400';
    const textNormal = isDark ? 'text-[#e6edf3]'  : 'text-gray-700';
    const btnHover   = isDark ? 'hover:bg-[#21262d] hover:text-[#e6edf3]' : 'hover:text-gray-600 hover:bg-gray-50';

    return (
        <aside className={`hidden lg:flex flex-col sticky top-16 h-[calc(100vh-4rem)] ${bg} border-l ${border} shadow-sm transition-all duration-200 shrink-0 ${expandida ? 'w-44' : 'w-14'}`}>
            <button
                onClick={() => setExpandida(e => !e)}
                className={`flex items-center justify-center h-10 w-full border-b ${border} ${textMuted} ${btnHover} transition`}
            >
                <svg className={`w-4 h-4 transition-transform duration-200 ${expandida ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                </svg>
            </button>

            <div className="flex-1 overflow-y-auto py-3 px-2 space-y-2">
                {expandida && (
                    <p className={`text-xs font-semibold uppercase tracking-wider px-1 mb-2 ${textMuted}`}>
                        Online ({usuariosOnline.length})
                    </p>
                )}
                {usuariosOnline.map((u) => {
                    const isMe = u.id === currentUserId;
                    return (
                        <div key={u.id} className="flex items-center gap-2.5 relative">
                            <div className="relative shrink-0">
                                <Avatar
                                    user={{ name: u.name, avatar: u.avatar }}
                                    size={32}
                                    ring={isDark ? '#161b22' : '#ffffff'}
                                    title={isMe ? 'Você' : u.name}
                                />
                                <span className={`absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-green-400 border-2 ${isDark ? 'border-[#161b22]' : 'border-white'} rounded-full`} />
                            </div>
                            {expandida && (
                                <span className={`text-xs font-medium truncate ${textNormal}`}>
                                    {isMe ? 'Você' : u.name}
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>
        </aside>
    );
}
