<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Cria a conta do Hipermon — o perfil que conta as novidades do sistema no
     * chat (ver User::ROLE_SISTEMA).
     *
     * Só ACRESCENTA uma linha em `users`: não toca em nenhuma conta, nota ou
     * mensagem que já exista. A senha é aleatória e descartada aqui mesmo —
     * ninguém a conhece, e o login recusa o e-mail dele de qualquer forma.
     *
     * Idempotente: se a conta já existir (rodou antes, ou foi criada à mão),
     * não faz nada.
     */
    public function up(): void
    {
        if (DB::table('users')->where('email', 'hipermon@sistema.local')->exists()) {
            return;
        }

        DB::table('users')->insert([
            'name'         => 'Hipermon',
            'email'        => 'hipermon@sistema.local',
            'password'     => Hash::make(Str::random(64)),
            'role'         => 'sistema',
            'avatar_tipo'  => 'emoji',
            'avatar_valor' => '🤖',
            'notificacoes_ativas' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Desfazer apaga a conta, e com ela as conversas e mensagens que ela
     * mandou (são só as novidades). Mensagens de outras pessoas não entram.
     */
    public function down(): void
    {
        $id = DB::table('users')->where('email', 'hipermon@sistema.local')->value('id');

        if (! $id) {
            return;
        }

        $conversas = DB::table('conversa_participantes')->where('user_id', $id)->pluck('conversa_id');

        DB::table('mensagens')->whereIn('conversa_id', $conversas)->delete();
        DB::table('conversa_participantes')->whereIn('conversa_id', $conversas)->delete();
        DB::table('conversas')->whereIn('id', $conversas)->delete();
        DB::table('users')->where('id', $id)->delete();
    }
};
