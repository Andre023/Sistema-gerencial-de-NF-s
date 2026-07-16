<?php

namespace App\Models\Concerns;

use Carbon\Carbon;

/**
 * Idade e severidade de um registro pendente.
 *
 * Antes o sistema só sabia dizer "veio de outro dia" (sim/não) — uma pendência
 * de 1 dia e uma de 6 meses ficavam idênticas na tela. Aqui mora o único lugar
 * que define os limiares, usado pela listagem e pelas estatísticas.
 */
trait TemIdade
{
    /** Dias em aberto a partir dos quais cada nível começa. */
    public const SLA_ATENCAO = 1;
    public const SLA_ALERTA  = 3;
    public const SLA_CRITICO = 7;

    public const NIVEL_NORMAL  = 'normal';
    public const NIVEL_ATENCAO = 'atencao';
    public const NIVEL_ALERTA  = 'alerta';
    public const NIVEL_CRITICO = 'critico';

    /** Níveis que representam uma pendência envelhecida (do mais grave ao mais leve). */
    public const NIVEIS_ALERTA = [self::NIVEL_CRITICO, self::NIVEL_ALERTA, self::NIVEL_ATENCAO];

    /**
     * Dias entre a criação e a data consultada.
     *
     * Relativo ao filtro (não a "hoje") para bater com a lógica de pendentes que
     * arrastam: ao navegar para um dia passado, a idade é a daquele dia.
     */
    public function diasEmAberto(string $dataFiltro): int
    {
        $criada = $this->created_at->copy()->startOfDay();
        $ref    = Carbon::parse($dataFiltro)->startOfDay();

        // Carbon 3: diffInDays é assinado — criada antes da referência dá positivo.
        return max(0, (int) $criada->diffInDays($ref));
    }

    public function isAtrasada(string $dataFiltro): bool
    {
        return $this->created_at->toDateString() < $dataFiltro;
    }

    public function nivelAlerta(string $dataFiltro): string
    {
        $dias = $this->diasEmAberto($dataFiltro);

        return match (true) {
            $dias >= self::SLA_CRITICO => self::NIVEL_CRITICO,
            $dias >= self::SLA_ALERTA  => self::NIVEL_ALERTA,
            $dias >= self::SLA_ATENCAO => self::NIVEL_ATENCAO,
            default                    => self::NIVEL_NORMAL,
        };
    }
}
