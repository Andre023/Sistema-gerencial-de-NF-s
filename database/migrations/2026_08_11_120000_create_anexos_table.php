<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos e fotos da nota (canhoto, avaria, PDF do pedido).
     *
     * O arquivo NÃO mora aqui nem em public/: fica em storage/app/private e só
     * sai por uma rota que confere permissão. Esta tabela guarda o ponteiro e o
     * que a tela precisa mostrar sem abrir o arquivo (nome, tipo, tamanho).
     *
     * `caminho` é único porque é o endereço físico: dois registros apontando
     * para o mesmo arquivo fariam a exclusão de um deixar o outro quebrado.
     *
     * Vida curta de propósito: some 2 dias depois de a nota ser liberada, e na
     * hora se ela for cancelada ou excluída (ver LimparAnexosDaNota).
     */
    public function up(): void
    {
        Schema::create('anexos', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete cobre o hard delete; o soft delete da nota não
            // dispara FK nenhuma, então quem apaga ali é o observer/job — o
            // arquivo em disco precisa sair junto, e disso o banco não sabe.
            $table->foreignId('nota_id')->constrained('notas')->cascadeOnDelete();

            $table->string('caminho')->unique();  // relativo ao disco 'privado'
            $table->string('nome_original');      // o que o usuário vê e baixa
            $table->string('mime', 100);
            $table->unsignedInteger('tamanho');   // bytes

            // Igual a cards e comentários: se o usuário sair, o histórico fica
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('nota_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anexos');
    }
};
