<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // operador | encarregado | admin
            $table->string('role')->default('operador')->after('email');
        });

        // O primeiro usuário existente vira admin para não travar o acesso ao sistema.
        $primeiro = DB::table('users')->orderBy('id')->value('id');
        if ($primeiro) {
            DB::table('users')->where('id', $primeiro)->update(['role' => 'admin']);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
