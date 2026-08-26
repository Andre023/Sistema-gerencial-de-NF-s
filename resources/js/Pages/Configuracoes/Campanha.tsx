import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Secoes, { Cartao } from '@/Components/configuracoes/Secoes';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';
import { MARCADORES } from '@/lib/campanha';

interface Props {
    ativa: boolean;
    textoPadrao: string;
    textoDeFabrica: string;
    limiteDeCaracteres: number;
}

/** Interruptor de verdade: o estado se lê de longe, sem depender da cor. */
function Interruptor({ ligado, onMudar, p, desabilitado }: {
    ligado: boolean; onMudar: (v: boolean) => void; p: Palette; desabilitado?: boolean;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={ligado}
            disabled={desabilitado}
            onClick={() => onMudar(!ligado)}
            className="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition disabled:opacity-50"
            style={{ background: ligado ? p.GREEN : p.INPUT_BORDER }}
        >
            <span
                className="inline-block h-4 w-4 rounded-full bg-white transition-transform"
                style={{ transform: `translateX(${ligado ? 24 : 4}px)` }}
            />
        </button>
    );
}

export default function Campanha({ ativa, textoPadrao, textoDeFabrica, limiteDeCaracteres }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const [texto, setTexto] = useState(textoPadrao);
    const [salvando, setSalvando] = useState(false);

    // Só volta a acompanhar o servidor quando o texto SALVO muda (ou seja,
    // depois de salvar). Ligar/desligar a aba não mexe no rascunho de quem
    // estava escrevendo.
    useEffect(() => setTexto(textoPadrao), [textoPadrao]);

    const alternar = (nova: boolean) => {
        setSalvando(true);
        router.patch(route('configuracoes.campanha.atualizar'),
            { ativa: nova, texto_padrao: textoPadrao },
            { preserveScroll: true, onFinish: () => setSalvando(false) });
    };

    const salvarTexto = () => {
        setSalvando(true);
        router.patch(route('configuracoes.campanha.atualizar'),
            { ativa, texto_padrao: texto },
            { preserveScroll: true, onFinish: () => setSalvando(false) });
    };

    const mudou = texto !== textoPadrao;

    return (
        <Secoes atual="campanha">
            <Head title="Configurações — Campanha" />

            <div className="space-y-5">

                <Cartao
                    titulo="Aba Campanha de aniversário"
                    descricao="A tela onde compras monta a carta do fornecedor e baixa o Word."
                    p={p}
                >
                    <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0">
                            <p className="text-sm font-medium" style={{ color: ativa ? p.GREEN : p.MUTED }}>
                                {ativa ? 'Ativa — a aba está no menu de compras' : 'Desativada — a aba não aparece para ninguém'}
                            </p>
                            <p className="text-xs mt-1.5 leading-relaxed" style={{ color: p.MUTED }}>
                                Desligada, a aba some do menu e o endereço também para de abrir — inclusive
                                para quem já estava com a tela aberta. Esta página continua aqui: é por ela
                                que a campanha volta no ano que vem.
                            </p>
                        </div>
                        <Interruptor ligado={ativa} onMudar={alternar} p={p} desabilitado={salvando} />
                    </div>
                </Cartao>

                <Cartao
                    titulo="Texto padrão da carta"
                    descricao="É o que todo comprador vê ao abrir a aba pela primeira vez — e o que volta quando alguém clica em “Restaurar texto padrão”. Cada um pode salvar a versão dele por cima, sem mexer neste."
                    p={p}
                >
                    <div className="flex flex-wrap items-center gap-1.5 mb-2.5">
                        <span className="text-xs" style={{ color: p.MUTED }}>Marcadores:</span>
                        {Object.values(MARCADORES).map(m => (
                            <code key={m} className="text-xs px-1.5 py-0.5 rounded"
                                style={{ background: isDark ? 'rgba(47,129,247,0.12)' : '#eaf2ff', color: p.ACCENT }}>
                                {m}
                            </code>
                        ))}
                    </div>

                    <textarea
                        value={texto}
                        onChange={e => setTexto(e.target.value.slice(0, limiteDeCaracteres))}
                        rows={16}
                        spellCheck
                        className="block w-full rounded-lg text-sm px-3 py-2.5 outline-none leading-relaxed resize-y"
                        style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
                    />

                    <div className="flex flex-wrap items-center gap-2 mt-3">
                        <button
                            onClick={salvarTexto}
                            disabled={salvando || !mudou}
                            className="text-sm font-medium px-3.5 py-2 rounded-lg transition disabled:opacity-50"
                            style={{ background: p.ACCENT, color: '#fff' }}
                        >
                            {salvando ? 'Salvando…' : 'Salvar texto padrão'}
                        </button>

                        <button
                            onClick={() => setTexto(textoDeFabrica)}
                            disabled={salvando || texto === textoDeFabrica}
                            className="text-sm px-3.5 py-2 rounded-lg transition disabled:opacity-40"
                            style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}
                        >
                            Voltar ao texto de fábrica
                        </button>

                        <span className="text-xs ml-auto" style={{ color: p.MUTED }}>
                            {texto.length.toLocaleString('pt-BR')} / {limiteDeCaracteres.toLocaleString('pt-BR')}
                        </span>
                    </div>

                    {mudou && (
                        <p className="text-xs mt-2" style={{ color: p.AMBER }}>
                            Há alterações não salvas.
                        </p>
                    )}
                </Cartao>
            </div>
        </Secoes>
    );
}
