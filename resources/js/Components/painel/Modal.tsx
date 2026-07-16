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
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onFechar} />
            <div className="relative rounded-2xl shadow-2xl w-full max-w-lg"
                style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                <div className="flex items-center justify-between px-6 pt-5 pb-4"
                    style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                    <h3 className="text-sm font-semibold" style={{ color: p.TEXT }}>{titulo}</h3>
                    <button onClick={onFechar} className="p-0.5 rounded transition-colors"
                        style={{ color: p.MUTED }}
                        onMouseEnter={e => (e.currentTarget.style.color = p.TEXT)}
                        onMouseLeave={e => (e.currentTarget.style.color = p.MUTED)}>
                        <Icone path="M6 18L18 6M6 6l12 12" />
                    </button>
                </div>
                <div className="px-6 py-5">{children}</div>
            </div>
        </div>
    );
}
