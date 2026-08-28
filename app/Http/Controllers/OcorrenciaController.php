<?php

namespace App\Http\Controllers;

use App\Models\Nota;
use App\Models\Ocorrencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * O livro de ocorrências da nota — só leitura.
 *
 * Não há store, update nem destroy, e isso é o desenho: um registro que a
 * aplicação sabe escrever fora da ação, corrigir ou apagar não serve para o que
 * ele existe. Quem escreve são os observers (App\Observers), na hora do fato.
 */
class OcorrenciaController extends Controller
{
    public function index(Request $request, Nota $nota): JsonResponse
    {
        Gate::authorize('ver-ocorrencias');

        $ocorrencias = Ocorrencia::with('user:id,name')
            ->where('nota_id', $nota->id)
            // Do mais novo para o mais antigo: quem abre quer saber o que
            // acabou de acontecer, não o lançamento de três dias atrás.
            ->orderByDesc('created_at')
            ->orderByDesc('id') // desempate estável em ações do mesmo segundo
            ->get()
            ->map(fn(Ocorrencia $o) => $o->paraTela());

        return response()->json([
            'ocorrencias' => $ocorrencias,
            // O vocabulário vai junto: os rótulos de campo moram no servidor
            // (Ocorrencia::CAMPOS) para não existir uma segunda lista na tela,
            // que é como as listas de tipo de card saíram de sincronia.
            'campos'      => Ocorrencia::CAMPOS,
        ]);
    }
}
