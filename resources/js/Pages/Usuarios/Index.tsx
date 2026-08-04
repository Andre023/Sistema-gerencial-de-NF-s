import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Papel } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';
import Icone from '@/Components/painel/Icone';
import Modal from '@/Components/painel/Modal';

interface Usuario {
    id: number;
    name: string;
    email: string;
    role: Papel;
    created_at: string;
}

interface Props {
    usuarios: Usuario[];
    papeis: Papel[];
}

const PAPEL_LABEL: Record<Papel, string> = {
    recebimento: 'Recebimento',
    pre_lote: 'Pré-lote',
    compras: 'Compras',
    visitante: 'Visitante',
    admin: 'Admin',
};

function papelCor(papel: Papel, p: Palette): string {
    if (papel === 'admin') return p.GREEN;
    if (papel === 'pre_lote') return p.PURPLE;
    if (papel === 'compras') return p.AMBER;
    if (papel === 'visitante') return p.MUTED;
    return p.ACCENT;
}

// ─── Formulário ───────────────────────────────────────────────────────────────

interface DadosForm {
    name: string; email: string; password: string; password_confirmation: string; role: Papel;
}

function FormUsuario({ papeis, inicial, onSubmit, onCancelar, carregando, erros, labelSubmit, edicao, p }: {
    papeis: Papel[]; inicial?: Usuario; onSubmit: (d: DadosForm) => void; onCancelar: () => void;
    carregando: boolean; erros: Record<string, string>; labelSubmit: string; edicao: boolean; p: Palette;
}) {
    const [form, setForm] = useState<DadosForm>({
        name: inicial?.name ?? '', email: inicial?.email ?? '',
        password: '', password_confirmation: '', role: inicial?.role ?? 'recebimento',
    });

    const set = <K extends keyof DadosForm>(k: K, v: DadosForm[K]) => setForm(prev => ({ ...prev, [k]: v }));

    const inputStyle = (hasErr?: boolean) => ({
        background: p.INPUT_BG, color: p.TEXT,
        border: `1px solid ${hasErr ? p.RED : p.INPUT_BORDER}`,
    });

    const campo = (label: string, obrigatorio: boolean, children: React.ReactNode, erro?: string) => (
        <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>
                {label}{obrigatorio && <span style={{ color: p.RED }} className="ml-0.5">*</span>}
            </label>
            {children}
            {erro && <p className="text-xs mt-1" style={{ color: p.RED }}>{erro}</p>}
        </div>
    );

    return (
        <form onSubmit={e => { e.preventDefault(); onSubmit(form); }} className="space-y-4">
            {campo('Nome', true,
                <input type="text" value={form.name} onChange={e => set('name', e.target.value)}
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle(!!erros.name)} />, erros.name
            )}
            {campo('E-mail', true,
                <input type="email" value={form.email} onChange={e => set('email', e.target.value)}
                    autoComplete="off"
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle(!!erros.email)} />, erros.email
            )}
            {campo(edicao ? 'Nova senha (deixe em branco para manter)' : 'Senha', !edicao,
                <input type="password" value={form.password} onChange={e => set('password', e.target.value)}
                    autoComplete="new-password"
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle(!!erros.password)} />, erros.password
            )}
            {(form.password !== '' || !edicao) && campo('Confirmar senha', !edicao,
                <input type="password" value={form.password_confirmation}
                    onChange={e => set('password_confirmation', e.target.value)}
                    autoComplete="new-password"
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle()} />
            )}
            {campo('Papel', true,
                <select value={form.role} onChange={e => set('role', e.target.value as Papel)}
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle(!!erros.role)}>
                    {papeis.map(r => <option key={r} value={r}>{PAPEL_LABEL[r]}</option>)}
                </select>, erros.role
            )}
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 sm:gap-3 pt-3 mt-1" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                <button type="button" onClick={onCancelar} className="px-4 py-2.5 text-sm rounded-lg" style={{ color: p.MUTED }}>
                    Cancelar
                </button>
                <button type="submit" disabled={carregando}
                    className="px-5 py-2.5 text-sm font-medium text-white rounded-lg transition disabled:opacity-50"
                    style={{ background: p.ACCENT }}>
                    {carregando ? 'Salvando...' : labelSubmit}
                </button>
            </div>
        </form>
    );
}

// ─── Página ─────────────────────────────────────────────────────────────────────

export default function Index({ usuarios, papeis }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const meuId = usePage().props.auth.user.id;

    const [modalNovo, setModalNovo] = useState(false);
    const [modalEditar, setModalEditar] = useState<Usuario | null>(null);
    const [erros, setErros] = useState<Record<string, string>>({});
    const [submetendo, setSubmetendo] = useState(false);

    const criar = (dados: DadosForm) => {
        setSubmetendo(true);
        router.post(route('usuarios.store'), dados as any, {
            onSuccess: () => { setModalNovo(false); setErros({}); },
            onError: e => setErros(e),
            onFinish: () => setSubmetendo(false),
        });
    };

    const salvarEdicao = (dados: DadosForm) => {
        if (!modalEditar) return;
        setSubmetendo(true);
        const payload: Record<string, unknown> = { name: dados.name, email: dados.email, role: dados.role };
        if (dados.password) { payload.password = dados.password; payload.password_confirmation = dados.password_confirmation; }
        router.patch(route('usuarios.update', modalEditar.id), payload as any, {
            onSuccess: () => { setModalEditar(null); setErros({}); },
            onError: e => setErros(e),
            onFinish: () => setSubmetendo(false),
        });
    };

    const excluir = (u: Usuario) => {
        if (!confirm(`Excluir o usuário "${u.name}"?`)) return;
        router.delete(route('usuarios.destroy', u.id));
    };

    // No celular a coluna de e-mail sai (ela sozinha fazia a tabela passar de
    // 550px) e o endereço aparece embaixo do nome.
    const COLS: { rotulo: string; cls?: string }[] = [
        { rotulo: 'Nome' },
        { rotulo: 'E-mail', cls: 'hidden sm:table-cell' },
        { rotulo: 'Papel' },
        { rotulo: '' },
    ];

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Usuários" />

            <Modal aberto={modalNovo} onFechar={() => { setModalNovo(false); setErros({}); }} titulo="Novo usuário" p={p}>
                <FormUsuario papeis={papeis} onSubmit={criar} onCancelar={() => { setModalNovo(false); setErros({}); }}
                    carregando={submetendo} erros={erros} labelSubmit="Criar usuário" edicao={false} p={p} />
            </Modal>

            <Modal aberto={!!modalEditar} onFechar={() => { setModalEditar(null); setErros({}); }} titulo="Editar usuário" p={p}>
                {modalEditar && (
                    <FormUsuario papeis={papeis} inicial={modalEditar} onSubmit={salvarEdicao}
                        onCancelar={() => { setModalEditar(null); setErros({}); }}
                        carregando={submetendo} erros={erros} labelSubmit="Salvar alterações" edicao p={p} />
                )}
            </Modal>

            <div className="flex-1 w-full py-6 px-4 sm:px-6 lg:px-8 max-w-4xl mx-auto space-y-4 transition-colors duration-200"
                style={{ background: p.BG }}>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-lg font-semibold" style={{ color: p.TEXT }}>Usuários</h1>
                    <button onClick={() => setModalNovo(true)}
                        className="w-full sm:w-auto flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-medium text-white rounded-lg transition"
                        style={{ background: p.ACCENT }}
                        onMouseEnter={e => (e.currentTarget.style.filter = 'brightness(1.1)')}
                        onMouseLeave={e => (e.currentTarget.style.filter = 'none')}>
                        <Icone path="M12 4v16m8-8H4" /> Novo usuário
                    </button>
                </div>

                <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="rolagem-x">
                        <table className="min-w-full">
                            <thead>
                                <tr style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                    {COLS.map(c => (
                                        <th key={c.rotulo} className={`px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide whitespace-nowrap ${c.cls ?? ''}`}
                                            style={{ color: p.MUTED }}>{c.rotulo}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {usuarios.map(u => (
                                    <tr key={u.id} className="group" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                        <td className="px-3 sm:px-4 py-3 text-sm font-medium max-w-[40vw] sm:max-w-none" style={{ color: p.TEXT }}>
                                            <span className="block truncate">
                                                {u.name}
                                                {u.id === meuId && (
                                                    <span className="ml-2 text-xs font-normal" style={{ color: p.MUTED }}>(você)</span>
                                                )}
                                            </span>
                                            {/* O e-mail perde a coluna no celular e vem para cá */}
                                            <span className="sm:hidden block text-xs truncate font-normal mt-0.5" style={{ color: p.MUTED }}>
                                                {u.email}
                                            </span>
                                        </td>
                                        <td className="hidden sm:table-cell px-3 sm:px-4 py-3 text-sm max-w-[220px] truncate" style={{ color: p.MUTED }} title={u.email}>{u.email}</td>
                                        <td className="px-3 sm:px-4 py-3">
                                            <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
                                                style={{ background: papelCor(u.role, p) + '1a', color: papelCor(u.role, p), border: `1px solid ${papelCor(u.role, p)}40` }}>
                                                {PAPEL_LABEL[u.role]}
                                            </span>
                                        </td>
                                        <td className="px-3 sm:px-4 py-3 text-right">
                                            <div className="flex items-center justify-end gap-0.5 acoes-hover">
                                                <button onClick={() => { setErros({}); setModalEditar(u); }} title="Editar"
                                                    className="p-1.5 rounded-lg transition" style={{ color: p.ACCENT }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.ACCENT + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </button>
                                                {u.id !== meuId && (
                                                    <button onClick={() => excluir(u)} title="Excluir"
                                                        className="p-1.5 rounded-lg transition" style={{ color: p.RED }}
                                                        onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                        <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
