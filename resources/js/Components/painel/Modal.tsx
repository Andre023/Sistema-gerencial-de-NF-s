import React, { useEffect } from 'react';
import { Palette } from '@/lib/tema';
import Icone from './Icone';

export default function Modal({ aberto, onFechar, titulo, children, p }: {
    aberto: boolean; onFechar: () => void; titulo: string; children: React.ReactNode; p: Palette;
}) {
    useEffect(() => {
        if (!aberto) return;
        const fn = (e: KeyboardEvent) => { if (e.key === 'Escape') onFechar(); };
        window.addEventListener('keydown', fn);
        return () => window.removeEventListener('keydown', fn);
    }, [aberto, onFechar]);

    if (!aberto) return null;

    return (
        /* No celular o modal encosta embaixo (estilo "gaveta"): fica perto do
           polegar e ocupa a largura toda. No desktop segue centralizado. */
        <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
            {/* touch-none: sem isso, arrastar o dedo sobre o fundo escuro rolava
                a página lá atrás enquanto o modal estava aberto. */}
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm touch-none" onClick={onFechar} />

            {/* Altura limitada + corpo com rolagem própria: formulário grande em
                tela pequena antes vazava para fora e não dava para rolar. */}
            <div className="relative rounded-t-2xl sm:rounded-2xl shadow-2xl w-full sm:max-w-lg flex flex-col max-h-[92dvh] sm:max-h-[88dvh]"
                style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                <div className="flex items-center justify-between gap-3 px-4 sm:px-6 pt-5 pb-4 shrink-0"
                    style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                    <h3 className="text-sm font-semibold min-w-0" style={{ color: p.TEXT }}>{titulo}</h3>
                    <button onClick={onFechar} aria-label="Fechar"
                        className="p-1.5 -m-1.5 rounded transition-colors shrink-0"
                        style={{ color: p.MUTED }}
                        onMouseEnter={e => (e.currentTarget.style.color = p.TEXT)}
                        onMouseLeave={e => (e.currentTarget.style.color = p.MUTED)}>
                        <Icone path="M6 18L18 6M6 6l12 12" />
                    </button>
                </div>
                <div className="px-4 sm:px-6 py-5 overflow-y-auto overscroll-contain pb-[max(1.25rem,env(safe-area-inset-bottom))]">
                    {children}
                </div>
            </div>
        </div>
    );
}
