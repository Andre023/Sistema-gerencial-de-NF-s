<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sem índice único em `nome`, o upsert(['nome']) do FornecedorController
     * nunca deduplica — reimportar o mesmo JSON criava fornecedores repetidos.
     */
    public function up(): void
    {
        // 1. Colapsa duplicados (mesmo nome) no menor id, repontando as FKs
        $duplicados = DB::table('fornecedores')
            ->select('nome', DB::raw('MIN(id) as manter_id'), DB::raw('COUNT(*) as total'))
            ->groupBy('nome')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicados as $dup) {
            $idsRemover = DB::table('fornecedores')
                ->where('nome', $dup->nome)
                ->where('id', '!=', $dup->manter_id)
                ->pluck('id');

            DB::table('requisicoes')->whereIn('fornecedor_id', $idsRemover)
                ->update(['fornecedor_id' => $dup->manter_id]);
            DB::table('cadastros')->whereIn('fornecedor_id', $idsRemover)
                ->update(['fornecedor_id' => $dup->manter_id]);

            DB::table('fornecedores')->whereIn('id', $idsRemover)->delete();
        }

        // 2. Garante a unicidade daqui pra frente
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->unique('nome');
        });
    }

    public function down(): void
    {
        Schema::table('fornecedores', function (Blueprint $table) {
            $table->dropUnique(['nome']);
        });
    }
};
