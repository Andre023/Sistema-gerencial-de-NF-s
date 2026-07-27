<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Avatar personalizado do usuário — substitui o 🙋‍♂️ da fila e vira a
     * identidade visual no header. Duas formas guardadas em duas colunas:
     *   • tipo  = 'emoji' | 'monograma'
     *   • valor = o emoji já com tom de pele (ex.: "🧑🏾‍💼") OU a cor do monograma
     * Default 'monograma': sem configurar nada, todo mundo já ganha as iniciais
     * numa cor derivada do nome (o Avatar do front resolve a cor quando valor é null).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_tipo')->default('monograma')->after('role');
            $table->string('avatar_valor')->nullable()->after('avatar_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_tipo', 'avatar_valor']);
        });
    }
};
