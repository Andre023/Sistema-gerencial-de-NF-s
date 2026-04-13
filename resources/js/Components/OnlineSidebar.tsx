import { useState, useEffect } from 'react';

interface UsuarioOnline {
    id: number;
    name: string;
}

interface Props {
    currentUserId: number;
}

function corAvatar(nome: string): string {
    const cores = [
        'bg-blue-500', 'bg-purple-500', 'bg-green-500',
        'bg-orange-500', 'bg-rose-500', 'bg-teal-500', 'bg-indigo-500',
    ];
    let hash = 0;
    for (let i = 0; i < nome.length; i++) hash += nome.charCodeAt(i);
    return cores[hash % cores.length];
}

function Iniciais({ nome, cor }: { nome: string; cor: string }) {
    return (
        <span
            title={nome}
            className={`w-8 h-8 rounded-full ${cor} text-white text-xs font-bold flex items-center justify-center shrink-0 ring-2 ring-white`}
        >
            {nome.slice(0, 2).toUpperCase()}
        </span>
    );
}

export default function OnlineSidebar({ currentUserId }: Props) {
    const [expandida, setExpandida] = useState(false);
    const [usuariosOnline, setUsuariosOnline] = useState<UsuarioOnline[]>([]);

    useEffect(() => {
        // Entra no canal de presença configurado no Laravel
        const channel = window.Echo.join('presenca.sistema')
            // Assim que eu entro, recebo a lista de quem já está lá
            .here((users: UsuarioOnline[]) => {
                setUsuariosOnline(users);
            })
            // Alguém acabou de entrar
            .joining((user: UsuarioOnline) => {
                setUsuariosOnline((prev) => [...prev, user]);
            })
            // Alguém fechou o navegador ou deslogou
            .leaving((user: UsuarioOnline) => {
                setUsuariosOnline((prev) => prev.filter((u) => u.id !== user.id));
            })
            .error((error: any) => {
                console.error('Erro no Reverb:', error);
            });

        return () => {
            window.Echo.leave('presenca.sistema');
        };
    }, []);

    return (
        <aside
            className={`
                hidden lg:flex flex-col sticky top-16 h-[calc(100vh-4rem)]
                bg-white border-l border-gray-200 shadow-sm transition-all duration-200 shrink-0
                ${expandida ? 'w-44' : 'w-14'}
            `}
        >
            <button
                onClick={() => setExpandida(e => !e)}
                className="flex items-center justify-center h-10 w-full border-b border-gray-100 text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition"
            >
                <svg
                    className={`w-4 h-4 transition-transform duration-200 ${expandida ? 'rotate-180' : ''}`}
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                </svg>
            </button>

            <div className="flex-1 overflow-y-auto py-3 px-2 space-y-2">
                {expandida && (
                    <p className="text-xs text-gray-400 font-semibold uppercase tracking-wider px-1 mb-2">
                        Online ({usuariosOnline.length})
                    </p>
                )}

                {usuariosOnline.map((u: UsuarioOnline) => {
                    const cor = corAvatar(u.name);
                    const isMe = u.id === currentUserId;
                    return (
                        <div key={u.id} className="flex items-center gap-2.5 relative">
                            <div className="relative shrink-0">
                                <Iniciais nome={isMe ? 'Você' : u.name} cor={cor} />
                                <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-green-400 border-2 border-white rounded-full" />
                            </div>
                            {expandida && (
                                <span className="text-xs font-medium text-gray-700 truncate">
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