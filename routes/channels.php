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
