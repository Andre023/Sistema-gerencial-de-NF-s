<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('presenca.sistema', function (User $user) {
    // Retornamos os dados que queremos que os outros vejam (inclui o avatar,
    // para a barra lateral mostrar a identidade de cada um).
    return [
        'id'     => $user->id,
        'name'   => $user->name,
        'avatar' => $user->avatar,
    ];
});

Broadcast::channel('notas', function ($user) {
    return true;
});

// Quadro de devoluções: quem enxerga o quadro acompanha o que muda nele.
Broadcast::channel('devolucoes', function (User $user) {
    return $user->podeUsarDevolucoes();
});

// Sino: cada um só escuta o próprio canal
Broadcast::channel('usuario.{id}', function (User $user, int $id) {
    return $user->id === $id;
});
