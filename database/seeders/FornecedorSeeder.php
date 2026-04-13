<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Fornecedor;
use Illuminate\Support\Facades\File;

class FornecedorSeeder extends Seeder
{
    public function run(): void
    {
        // Define o caminho onde o seu arquivo JSON deve estar
        $caminhoArquivo = storage_path('app/fornecedores.json');

        // Verifica se o arquivo existe antes de tentar ler
        if (!File::exists($caminhoArquivo)) {
            $this->command->error("Arquivo JSON não encontrado em: {$caminhoArquivo}");
            return;
        }

        $json = File::get($caminhoArquivo);
        $fornecedores = json_decode($json, true);

        $this->command->info('Importando fornecedores...');

        foreach ($fornecedores as $fornecedor) {
            // Supondo que seu JSON tenha as chaves "nome" e "cnpj"
            Fornecedor::updateOrCreate(
                // A condição de busca (para não duplicar)
                ['nome' => $fornecedor['nome']],
                // Os dados a preencher
                ['cnpj' => $fornecedor['cnpj'] ?? null]
            );
        }

        $this->command->info('Fornecedores importados com sucesso!');
    }
}
