<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O estado intermediário "corrigido" foi removido: corrigir já resolve o card.
     * Converte os cards que estavam nesse limbo (compras corrigiu, aguardando o
     * pré-lote) para "resolvido" — que é o que passam a significar.
     */
    public function up(): void
    {
        DB::table('cards')->where('status', 'corrigido')->update(['status' => 'resolvido']);
    }

    public function down(): void
    {
        // Volta ao limbo os que compras havia corrigido mas o pré-lote não confirmou
        DB::table('cards')
            ->where('status', 'resolvido')
            ->whereNotNull('corrigido_em')
            ->whereNull('resolvido_em')
            ->update(['status' => 'corrigido']);
    }
};
