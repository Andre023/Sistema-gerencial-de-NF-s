<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Esta pessoa faz recebimento E pré-lote."
     *
     * O sistema aceita um papel por conta, mas na loja 12 as mesmas pessoas
     * recebem o caminhão e analisam a nota. Com papel de pré-lote, elas caíam
     * na regra do Notificador que cala o aviso quando quem lança já é do setor
     * que analisa — regra escrita supondo uma operação só, onde quem lança é
     * quem confere. Resultado: a central (loja 2) não sabia que havia nota nova
     * vinda da loja 12, enquanto notas do recebimento da própria loja avisavam
     * normalmente.
     *
     * Marcado, o que a pessoa lança volta a avisar o pré-lote. Só tem efeito em
     * conta com papel pré-lote: quem é do recebimento já avisa por padrão.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('acumula_recebimento')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('acumula_recebimento');
        });
    }
};
