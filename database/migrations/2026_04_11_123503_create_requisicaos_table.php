<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('requisicoes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_nota')->index(); // Indexado para buscas muito rápidas no futuro

            // Relacionamentos
            $table->foreignId('fornecedor_id')->constrained('fornecedores')->restrictOnDelete();
            // 'restrictOnDelete' impede que um fornecedor seja apagado se ele tiver notas vinculadas, mantendo a integridade

            $table->foreignId('user_id')->constrained('users'); // Quem solicitou a nota

            // Dados da nota
            $table->integer('loja'); // Ex: 1, 2, 3, 9, 11, 12
            $table->string('motivo'); // Cadastro, Preço, Regra, Quantidade
            $table->text('observacao')->nullable();

            // O controle de estado da nota
            $table->string('status')->default('Pendente');

            $table->timestamps(); // Cria 'created_at' e 'updated_at' (essenciais para nossa lógica de virada de dia)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requisicaos');
    }
};
