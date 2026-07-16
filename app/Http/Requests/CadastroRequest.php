<?php

namespace App\Http\Requests;

use App\Models\Cadastro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CadastroRequest extends FormRequest
{
    /** Campos cuja edição exige encarregado+ (mudar só o status = "atender", liberado a todos). */
    private const CAMPOS_EDICAO = ['numero_nota', 'fornecedor_id', 'loja', 'motivo', 'observacao'];

    public function authorize(): bool
    {
        // Criar e atender (só status) são liberados; editar campos exige gestão.
        if ($this->isMethod('patch') && $this->hasAny(self::CAMPOS_EDICAO)) {
            return Gate::allows('gerenciar-registros');
        }
        return true;
    }

    public function rules(): array
    {
        $obrig = fn(string $r) => ($this->isMethod('post') ? 'required|' : 'sometimes|') . $r;

        $rules = [
            'numero_nota'   => $obrig('string|max:30'),
            'fornecedor_id' => $obrig('exists:fornecedores,id'),
            'loja'          => $obrig('integer|in:' . implode(',', Cadastro::LOJAS)),
            'motivo'        => $obrig('string|in:' . implode(',', Cadastro::MOTIVOS)),
            'observacao'    => 'nullable|string|max:500',
        ];

        // Status só é aceito na edição — na criação é sempre "Pendente".
        if ($this->isMethod('patch')) {
            $rules['status'] = 'sometimes|string|in:' . implode(',', Cadastro::STATUS);
        }

        return $rules;
    }
}
