<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duas tabelas pequenas que sustentam a aba Campanha:
 *
 * - `configuracoes`: chave/valor do sistema. Nasce com uma chave só (a campanha
 *   ligada ou desligada) e o texto padrão da carta, mas fica genérica de
 *   propósito — a alternativa era uma coluna nova a cada interruptor.
 *
 * - `campanha_textos`: o esqueleto do texto de CADA comprador. Fica fora da
 *   tabela `users` porque o usuário logado viaja inteiro em toda página
 *   (Inertia compartilha `auth.user`), e um texto de milhares de caracteres
 *   junto seria peso em requisição que não tem nada a ver com a campanha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes', function (Blueprint $table) {
            $table->string('chave', 60)->primary();
            $table->text('valor')->nullable();
            $table->timestamps();
        });

        Schema::create('campanha_textos', function (Blueprint $table) {
            $table->id();
            // unique: um perfil por pessoa. cascade: conta apagada leva o texto.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('texto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanha_textos');
        Schema::dropIfExists('configuracoes');
    }
};
