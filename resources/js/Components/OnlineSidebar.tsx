/**
 * OnlineSidebar — Barra lateral de usuários online.
 *
 * Versão atual: estática (placeholder visual).
 * Versão futura: integrar com Laravel Reverb + Echo para presença em tempo real.
 *
 * Para ativar o realtime:
 *   1. Instalar: composer require laravel/reverb
 *   2. Instalar: npm install laravel-echo pusher-js
 *   3. Configurar BroadcastServiceProvider e canais de presença
 *   4. Substituir os dados estáticos abaixo pelo hook usePresenceChannel()
 */

import { useState } from 'react';

interface UsuarioOnline {
    id: number;
    name: string;
}

interface Props {
    currentUserId: number;
}

// Gera uma cor de avatar consistente baseada no nome
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

    // ── Dados estáticos (substituir por usePresenceChannel quando Reverb ativo) ──
    const usuariosOnline: UsuarioOnline[] = [
        { id: currentUserId, name: 'Você' },
    ];

    return (
        <aside
            className={`
                hidden lg:flex flex-col sticky top-16 h-[calc(100vh-4rem)]
                bg-white border-l border-gray-200 shadow-sm transition-all duration-200 shrink-0
                ${expandida ? 'w-44' : 'w-14'}
            `}
        >
            {/* Botão de toggle */}
            <button
                onClick={() => setExpandida(e => !e)}
                title={expandida ? 'Recolher' : 'Expandir'}
                className="flex items-center justify-center h-10 w-full border-b border-gray-100 text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition"
            >
                <svg
                    className={`w-4 h-4 transition-transform duration-200 ${expandida ? 'rotate-180' : ''}`}
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                </svg>
            </button>

            {/* Lista de usuários */}
            <div className="flex-1 overflow-y-auto py-3 px-2 space-y-2">
                {/* Indicador "Online" */}
                {expandida && (
                    <p className="text-xs text-gray-400 font-semibold uppercase tracking-wider px-1 mb-2">
                        Online ({usuariosOnline.length})
                    </p>
                )}

                {usuariosOnline.map(u => {
                    const cor = corAvatar(u.name);
                    return (
                        <div key={u.id} className="flex items-center gap-2.5 relative">
                            <div className="relative shrink-0">
                                <Iniciais nome={u.name} cor={cor} />
                                {/* Indicador verde de online */}
                                <span className="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-green-400 border-2 border-white rounded-full" />
                            </div>
                            {expandida && (
                                <span className="text-xs font-medium text-gray-700 truncate">
                                    {u.name}
                                </span>
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Rodapé quando expandido */}
            {expandida && (
                <div className="px-3 py-2 border-t border-gray-100">
                    <p className="text-xs text-gray-300 italic text-center">
                        Realtime em breve
                    </p>
                </div>
            )}
        </aside>
    );
}
