<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Reserva" da nota: quem está olhando/corrigindo agora (o 🙋‍♂️ da tela).
     * Serve para duas pessoas não pegarem a mesma nota ao mesmo tempo sem saber.
     * É soft: sinaliza, não trava. Some sozinha quando a pessoa age (abre/corrige
     * card, libera) — por isso não guardamos histórico, é um estado do agora.
     */
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->foreignId('visualizando_por')->nullable()->after('recebida_em')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('visualizando_em')->nullable()->after('visualizando_por');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visualizando_por');
            $table->dropColumn('visualizando_em');
        });
    }
};
