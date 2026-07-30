<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Expressões de data/hora que funcionam no MySQL (produção) E no SQLite (testes).
 *
 * As estatísticas usavam funções MySQL-only (TIMESTAMPDIFF, DAYOFWEEK, HOUR,
 * DATE_FORMAT) e por isso não podiam ser testadas — justamente a tela onde um
 * erro de conta passa despercebido. Aqui cada expressão tem as duas versões.
 */
trait SqlData
{
    private function ehSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** Média, em MINUTOS, entre duas colunas de data. */
    protected function avgMinutos(string $inicio, string $fim): string
    {
        return $this->ehSqlite()
            ? "AVG((julianday($fim) - julianday($inicio)) * 1440)"
            : "AVG(TIMESTAMPDIFF(MINUTE, $inicio, $fim))";
    }

    /** Dia da semana como número 1..7 (1 = domingo), igual ao DAYOFWEEK do MySQL. */
    protected function diaSemana(string $col): string
    {
        return $this->ehSqlite()
            ? "CAST(strftime('%w', $col) AS INTEGER) + 1"
            : "DAYOFWEEK($col)";
    }

    /** Hora do dia (0..23). */
    protected function hora(string $col): string
    {
        return $this->ehSqlite()
            ? "CAST(strftime('%H', $col) AS INTEGER)"
            : "HOUR($col)";
    }

    /** Competência "2026-07", para agrupar por mês. */
    protected function anoMes(string $col): string
    {
        return $this->ehSqlite()
            ? "strftime('%Y-%m', $col)"
            : "DATE_FORMAT($col, '%Y-%m')";
    }
}
