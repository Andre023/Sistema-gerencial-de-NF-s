<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma devolução pode ter VÁRIOS boletos.
     *
     * A coluna nascera com um vencimento só, e a operação mostrou que não é
     * assim: uma nota grande sai parcelada, e o recado precisava listar todas as
     * datas. Com uma só, quem lançava escolhia a primeira e as outras viravam
     * combinado de boca — que é exatamente o que este quadro veio tirar do ar.
     *
     * JSON e não tabela à parte: são datas soltas, sem nada preso a elas. Uma
     * tabela renderia um join a cada abertura do quadro para guardar uma lista
     * de cinco strings.
     */
    public function up(): void
    {
        Schema::table('devolucoes', function (Blueprint $table) {
            $table->json('boletos_vencem')->nullable()->after('autorizado_por');
        });

        // O que já existe vira uma lista de um item — ninguém perde a data.
        foreach (DB::table('devolucoes')->whereNotNull('boleto_vence')->get() as $linha) {
            DB::table('devolucoes')
                ->where('id', $linha->id)
                ->update(['boletos_vencem' => json_encode([
                    substr((string) $linha->boleto_vence, 0, 10),
                ])]);
        }

        Schema::table('devolucoes', function (Blueprint $table) {
            $table->dropColumn('boleto_vence');
        });
    }

    public function down(): void
    {
        Schema::table('devolucoes', function (Blueprint $table) {
            $table->date('boleto_vence')->nullable()->after('autorizado_por');
        });

        // Na volta sobra só a primeira data: a coluna antiga não comporta o resto.
        foreach (DB::table('devolucoes')->whereNotNull('boletos_vencem')->get() as $linha) {
            $datas = json_decode((string) $linha->boletos_vencem, true) ?: [];

            DB::table('devolucoes')
                ->where('id', $linha->id)
                ->update(['boleto_vence' => $datas[0] ?? null]);
        }

        Schema::table('devolucoes', function (Blueprint $table) {
            $table->dropColumn('boletos_vencem');
        });
    }
};
