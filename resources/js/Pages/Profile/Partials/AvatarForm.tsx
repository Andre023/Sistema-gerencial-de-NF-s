import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { PageProps, Avatar as AvatarTipo, TipoAvatar } from '@/types';
import Avatar from '@/Components/painel/Avatar';
import Twemoji from '@/Components/painel/Twemoji';
import {
    EMOJIS_BASE, TONS_PELE, TONS_LABEL, aplicarTom, aceitaTom, CORES_MONOGRAMA,
} from '@/lib/avatares';

/**
 * "Personalize seu avatar" — o avatar substitui o 🙋 na fila e vira a identidade
 * no header. Duas formas: emoji (com tom de pele) e monograma (iniciais numa
 * cor). O preview no topo mostra exatamente o que vai ser salvo.
 */
export default function AvatarForm({ className = '' }: { className?: string }) {
    const { auth } = usePage<PageProps>().props;
    const atual = auth.user.avatar;

    const [modo, setModo] = useState<TipoAvatar>(atual?.tipo ?? 'monograma');

    // Emoji
    const [baseEmoji, setBaseEmoji] = useState<string>('');
    const [tomIdx, setTomIdx] = useState<number>(0);
    const [valorEmoji, setValorEmoji] = useState<string>(atual?.tipo === 'emoji' ? (atual.valor ?? '') : '');

    // Monograma (null = cor automática derivada do nome)
    const [corMono, setCorMono] = useState<string | null>(atual?.tipo === 'monograma' ? (atual.valor ?? null) : null);

    const [salvando, setSalvando] = useState(false);
    const [salvo, setSalvo] = useState(false);

    const escolherBase = (base: string) => {
        setBaseEmoji(base);
        setValorEmoji(aplicarTom(base, aceitaTom(base) ? tomIdx : 0));
    };
    const escolherTom = (i: number) => {
        setTomIdx(i);
        if (baseEmoji) setValorEmoji(aplicarTom(baseEmoji, aceitaTom(baseEmoji) ? i : 0));
    };

    // O que o preview (e o backend) recebem conforme o modo escolhido
    const avatarPreview: AvatarTipo =
        modo === 'emoji'
            ? { tipo: 'emoji', valor: valorEmoji || null }
            : { tipo: 'monograma', valor: corMono };

    const podeSalvar = modo !== 'emoji' || valorEmoji !== '';

    const salvar = () => {
        setSalvando(true);
        router.patch(route('profile.avatar'), {
            tipo: modo,
            valor: modo === 'emoji' ? valorEmoji : corMono,
        }, {
            preserveScroll: true,
            onSuccess: () => { setSalvo(true); setTimeout(() => setSalvo(false), 2000); },
            onFinish: () => setSalvando(false),
        });
    };

    const aba = (m: TipoAvatar, texto: string) => (
        <button type="button" onClick={() => setModo(m)}
            className={`px-3 py-1.5 text-sm rounded-lg border transition ${
                modo === m ? 'border-blue-500 bg-blue-50 text-blue-700 font-medium' : 'border-gray-200 text-gray-600 hover:bg-gray-50'
            }`}>
            {texto}
        </button>
    );

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">Personalize seu avatar</h2>
                <p className="mt-1 text-sm text-gray-600">
                    Ele substitui o boneco na fila e aparece como sua identidade no topo.
                </p>
            </header>

            {/* Preview + abas */}
            <div className="mt-6 flex items-center gap-4">
                <Avatar user={{ name: auth.user.name, avatar: avatarPreview }} size={64} ring="#e5e7eb" />
                <div className="flex flex-wrap gap-2">
                    {aba('emoji', 'Emoji')}
                    {aba('monograma', 'Monograma')}
                </div>
            </div>

            {/* ── Emoji ── */}
            {modo === 'emoji' && (
                <div className="mt-5 space-y-3">
                    <div>
                        <p className="text-xs font-medium text-gray-500 mb-1.5">Tom de pele</p>
                        <div className="flex gap-1.5">
                            {TONS_PELE.map((_, i) => (
                                <button key={i} type="button" onClick={() => escolherTom(i)}
                                    title={TONS_LABEL[i]}
                                    className={`w-9 h-9 rounded-lg border flex items-center justify-center transition ${
                                        tomIdx === i ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'
                                    }`}>
                                    <Twemoji emoji={aplicarTom('🙋', i)} size={20} />
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="grid grid-cols-8 sm:grid-cols-9 gap-1.5">
                        {EMOJIS_BASE.map(base => {
                            const mostra = aplicarTom(base, aceitaTom(base) ? tomIdx : 0);
                            const sel = baseEmoji === base;
                            return (
                                <button key={base} type="button" onClick={() => escolherBase(base)}
                                    className={`aspect-square rounded-lg border flex items-center justify-center transition ${
                                        sel ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:bg-gray-50'
                                    }`}>
                                    <Twemoji emoji={mostra} size={24} />
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* ── Monograma ── */}
            {modo === 'monograma' && (
                <div className="mt-5">
                    <p className="text-xs font-medium text-gray-500 mb-1.5">Cor das iniciais</p>
                    <div className="flex flex-wrap gap-2 items-center">
                        <button type="button" onClick={() => setCorMono(null)}
                            className={`px-3 h-9 rounded-lg border text-sm transition ${
                                corMono === null ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'
                            }`}>
                            Automática
                        </button>
                        {CORES_MONOGRAMA.map(cor => (
                            <button key={cor} type="button" onClick={() => setCorMono(cor)}
                                title={cor}
                                className={`w-9 h-9 rounded-full transition ${corMono === cor ? 'ring-2 ring-offset-2 ring-blue-500' : ''}`}
                                style={{ background: cor }} />
                        ))}
                    </div>
                </div>
            )}

            {/* Salvar */}
            <div className="mt-6 flex items-center gap-4">
                <button type="button" onClick={salvar} disabled={salvando || !podeSalvar}
                    className="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition disabled:opacity-50">
                    {salvando ? 'Salvando...' : 'Salvar avatar'}
                </button>
                {salvo && <p className="text-sm text-gray-600">Salvo.</p>}
                {!podeSalvar && <p className="text-sm text-gray-500">Escolha um emoji.</p>}
            </div>
        </section>
    );
}
