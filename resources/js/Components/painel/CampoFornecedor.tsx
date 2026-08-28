import { useState, useMemo, useEffect, useRef } from 'react';
import { Fornecedor } from '@/types';
import { Palette } from '@/lib/tema';

export default function CampoFornecedor({ fornecedores, valor, onChange, erro, carregando = false, p }: {
    fornecedores: Fornecedor[]; valor: { id: number | ''; nome: string };
    /** A lista chega sob demanda; sem este aviso o campo parece vazio de verdade. */
    carregando?: boolean;
    onChange: (f: { id: number | ''; nome: string }) => void; erro?: string; p: Palette;
}) {
    const [busca, setBusca] = useState(valor.nome);
    const [aberto, setAberto] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    const opcoes = useMemo(() =>
        fornecedores.filter(f => f.nome.toLowerCase().includes(busca.toLowerCase())).slice(0, 12),
        [fornecedores, busca]
    );

    useEffect(() => {
        const fn = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setAberto(false);
        };
        document.addEventListener('mousedown', fn);
        return () => document.removeEventListener('mousedown', fn);
    }, []);

    const selecionar = (f: Fornecedor) => { onChange({ id: f.id, nome: f.nome }); setBusca(f.nome); setAberto(false); };

    return (
        <div ref={ref} className="relative">
            <input type="text" value={busca}
                onChange={e => { setBusca(e.target.value); onChange({ id: '', nome: e.target.value }); setAberto(true); }}
                onFocus={() => setAberto(true)}
                placeholder={carregando ? 'Carregando fornecedores...' : 'Buscar fornecedor...'}
                autoComplete="off"
                className="block w-full rounded-lg text-sm px-3 py-2 outline-none transition"
                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${erro ? p.RED : p.INPUT_BORDER}` }}
            />
            {aberto && opcoes.length > 0 && (
                <ul className="absolute z-20 mt-1 w-full rounded-xl shadow-lg max-h-52 overflow-y-auto"
                    style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    {opcoes.map(f => (
                        <li key={f.id}>
                            <button type="button" onMouseDown={() => selecionar(f)}
                                className="w-full text-left px-3.5 py-2 text-sm transition"
                                style={{ color: p.TEXT }}
                                onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                {f.nome}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {erro && <p className="text-xs mt-1" style={{ color: p.RED }}>{erro}</p>}
        </div>
    );
}
