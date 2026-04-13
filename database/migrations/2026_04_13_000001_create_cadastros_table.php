<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cadastros', function (Blueprint $table) {
            $table->id();
            $table->string('numero_nota')->index();

            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users');

            // Quando "Caminhão na porta" é selecionado, guarda o id da requisição gerada
            $table->foreignId('requisicao_id')->nullable()->constrained('requisicoes')->nullOnDelete();

            $table->integer('loja');
            $table->string('motivo'); // 'Pré Lote' | 'Caminhão na Porta'
            $table->text('observacao')->nullable();
            $table->string('status')->default('Pendente'); // Pendente | Atendida

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cadastros');
    }
};
