<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('presenca.sistema', function (User $user) {
    // Retornamos os dados que queremos que os outros vejam
    return [
        'id'   => $user->id,
        'name' => $user->name,
    ];
});

Broadcast::channel('notas', function ($user) {
    return true;
});

// Sino: cada um só escuta o próprio canal
Broadcast::channel('usuario.{id}', function (User $user, int $id) {
    return $user->id === $id;
});
