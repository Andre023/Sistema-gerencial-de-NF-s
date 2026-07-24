import { Transition } from '@headlessui/react';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageProps } from '@/types';

/**
 * Liga/desliga o sino. Salva na hora — não faz sentido um botão "salvar" para
 * um interruptor só.
 */
export default function NotificacoesForm({ className = '' }: { className?: string }) {
    const { auth } = usePage<PageProps>().props;

    const [ativas, setAtivas] = useState(auth.user.notificacoes_ativas ?? true);
    const [salvando, setSalvando] = useState(false);
    const [salvo, setSalvo] = useState(false);

    const alternar = () => {
        const valor = !ativas;

        setAtivas(valor); // otimista: o interruptor responde na hora
        setSalvando(true);

        router.patch(route('profile.notificacoes'), { notificacoes_ativas: valor }, {
            preserveScroll: true,
            onSuccess: () => {
                setSalvo(true);
                setTimeout(() => setSalvo(false), 2000);
            },
            onError: () => setAtivas(!valor), // deu erro: volta como estava
            onFinish: () => setSalvando(false),
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">Notificações</h2>

                <p className="mt-1 text-sm text-gray-600">
                    Avisos de divergência, correção e liberação de nota, no sino do topo.
                </p>
            </header>

            <div className="mt-6 flex items-center gap-4">
                <button
                    type="button"
                    role="switch"
                    aria-checked={ativas}
                    disabled={salvando}
                    onClick={alternar}
                    className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-50 ${
                        ativas ? 'bg-blue-600' : 'bg-gray-300'
                    }`}
                >
                    <span
                        className={`inline-block h-5 w-5 mt-0.5 rounded-full bg-white shadow transition-transform ${
                            ativas ? 'translate-x-[22px]' : 'translate-x-0.5'
                        }`}
                    />
                </button>

                <span className="text-sm text-gray-700">
                    {ativas ? 'Recebendo notificações' : 'Notificações desligadas'}
                </span>

                <Transition
                    show={salvo}
                    enter="transition ease-in-out"
                    enterFrom="opacity-0"
                    leave="transition ease-in-out"
                    leaveTo="opacity-0"
                >
                    <p className="text-sm text-gray-600">Salvo.</p>
                </Transition>
            </div>

            {!ativas && (
                <p className="mt-3 text-sm text-amber-700">
                    Você deixa de receber avisos novos. Os que já chegaram continuam no sino.
                </p>
            )}
        </section>
    );
}
