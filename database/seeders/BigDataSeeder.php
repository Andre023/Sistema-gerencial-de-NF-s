<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class BigDataSeeder extends Seeder
{
    // ── Configuração ──────────────────────────────────────────────────────────
    const DIAS_HISTORICO    = 90;
    const TOTAL_REQUISICOES = 600;
    const TOTAL_CADASTROS   = 250;
    const TOTAL_USUARIOS    = 6;

    const LOJAS   = [1, 2, 3, 9, 11, 12];
    const MOTIVOS_REQ = ['Cadastro', 'Preço', 'Regra', 'Quantidade', 'Pedido'];
    const MOTIVOS_CAD = ['Pré Lote', 'Caminhão na Porta'];

    // Peso por motivo (simulando distribuição real)
    const PESO_MOTIVO_REQ = [
        'Cadastro'   => 30,
        'Preço'      => 25,
        'Regra'      => 15,
        'Quantidade' => 20,
        'Pedido'     => 10,
    ];

    // Peso por loja (algumas lojas mais movimentadas)
    const PESO_LOJA = [
        1  => 25,
        2  => 20,
        3  => 18,
        9  => 15,
        11 => 12,
        12 => 10,
    ];

    public function run(): void
    {
        $this->command->info('🌱 Iniciando BigDataSeeder...');
        $this->command->newLine();

        Schema::disableForeignKeyConstraints();

        // Ordem importa por FK
        DB::table('requisicao_auditorias')->delete();
        DB::table('cadastros')->delete();
        DB::table('requisicoes')->delete();
        DB::table('fornecedores')->delete();
        DB::table('users')->where('email', '!=', 'admin@sistema.com')->delete();

        Schema::enableForeignKeyConstraints();

        $usuarios    = $this->seedUsuarios();
        $fornecedores = $this->seedFornecedores();
        $this->seedRequisicoes($usuarios, $fornecedores);
        $this->seedCadastros($usuarios, $fornecedores);

        $this->command->newLine();
        $this->command->info('✅ BigDataSeeder concluído com sucesso!');
        $this->command->newLine();
        $this->command->line('  📧 Usuários criados:');
        foreach ($usuarios as $u) {
            $this->command->line("     {$u['email']}  →  senha: <comment>password</comment>");
        }
        $this->command->newLine();
    }

    // ── Usuários ──────────────────────────────────────────────────────────────

    private function seedUsuarios(): array
    {
        $this->command->info('👤 Criando usuários...');

        $lista = [
            ['name' => 'Admin Sistema',    'email' => 'admin@sistema.com'],
            ['name' => 'Ana Paula',         'email' => 'ana@sistema.com'],
            ['name' => 'Carlos Mendes',     'email' => 'carlos@sistema.com'],
            ['name' => 'Fernanda Lima',     'email' => 'fernanda@sistema.com'],
            ['name' => 'João Vitor',        'email' => 'joao@sistema.com'],
            ['name' => 'Mariana Costa',     'email' => 'mariana@sistema.com'],
        ];

        $inseridos = [];
        foreach ($lista as $u) {
            $id = DB::table('users')->insertGetId([
                'name'              => $u['name'],
                'email'             => $u['email'],
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            $inseridos[] = array_merge($u, ['id' => $id]);
        }

        $this->command->line("   → " . count($inseridos) . " usuários criados.");
        return $inseridos;
    }

    // ── Fornecedores ──────────────────────────────────────────────────────────

    private function seedFornecedores(): array
    {
        $this->command->info('🏭 Criando fornecedores...');

        $nomes = [
            'Distribuidora Alfa Ltda',
            'Comercial Beta S/A',
            'Indústria Gama ME',
            'Atacadão Delta Distribuidora',
            'Frigorífico Epsilon Ltda',
            'Laticínios Zeta S/A',
            'Cerealista Eta Comércio',
            'Panificadora Theta ME',
            'Bebidas Iota Ltda',
            'Hortifruti Kappa S/A',
            'Carnes Nobre Lambda Ltda',
            'Mercearia Mu Distribuidora',
            'Alimentos Nu S/A',
            'Doces Xi ME',
            'Biscoitos Omicron Ltda',
            'Massas Pi Indústria',
            'Grãos Rho Comércio',
            'Temperos Sigma ME',
            'Conservas Tau Ltda',
            'Bebidas Upsilon S/A',
            'Laticínios Phi Comércio',
            'Rações Chi Distribuidora',
            'Embutidos Psi Ltda',
            'Frios Omega S/A',
            'Distribuidora Norte ME',
            'Atacado Sul Ltda',
            'Comércio Leste S/A',
            'Produtos Oeste ME',
            'Alimentos Central Ltda',
            'Frigorífico Novo Mundo S/A',
            'Indústria Premium ME',
            'Distribuidora Top Ltda',
            'Comercial Plus S/A',
            'Atacadista Max ME',
            'Fornecedor Mix Ltda',
            'Alimentos Select S/A',
            'Cerealista Nacional ME',
            'Bebidas Import Ltda',
            'Frios Brasil S/A',
            'Laticínios Primor ME',
            'Grãos e Farinhas Ltda',
            'Condimentos Real S/A',
            'Doces & Balas ME',
            'Massas Italianas Ltda',
            'Embutidos Chef S/A',
            'Conservas Gourmet ME',
            'Hortifruti Express Ltda',
            'Carnes Prime S/A',
            'Biscoitos Alegria ME',
            'Temperos do Sul Ltda',
            'Mercearia Boa Vista S/A',
            'Frigorífico São Paulo ME',
            'Distribuidora Rio Ltda',
            'Comércio Minas S/A',
            'Alimentos Bahia ME',
            'Bebidas Pernambuco Ltda',
            'Laticínios Goiás S/A',
            'Cerealista Paraná ME',
            'Grãos Santa Catarina Ltda',
            'Frios Rio Grande S/A',
            'Embutidos Nordeste ME',
            'Carnes Centro-Oeste Ltda',
            'Doces Sudeste S/A',
            'Massas Norte ME',
            'Temperos Brasil Ltda',
            'Conservas Nacional S/A',
            'Hortifruti Premium ME',
            'Biscoitos Gourmet Ltda',
            'Condimentos Select S/A',
            'Bebidas Especiais ME',
            'Laticínios Artesanais Ltda',
            'Frigorífico Rural S/A',
            'Distribuidora Campo ME',
            'Comercial Fazenda Ltda',
            'Alimentos Serra S/A',
            'Cerealista Vale ME',
            'Grãos Planalto Ltda',
            'Frios Litoral S/A',
            'Embutidos Sertão ME',
            'Carnes Pantanal Ltda',
            'Doces Cerrado S/A',
            'Massas Amazônia ME',
            'Temperos Caatinga Ltda',
            'Conservas Pampa S/A',
            'Hortifruti Mata ME',
            'Biscoitos Chapada Ltda',
            'Condimentos Mangue S/A',
            'Bebidas Pantaneira ME',
            'Laticínios Gaúcha Ltda',
            'Frigorífico Mineiro S/A',
            'Distribuidora Paulista ME',
            'Comercial Carioca Ltda',
            'Alimentos Nordestina S/A',
            'Cerealista Baiana ME',
            'Grãos Pernambucana Ltda',
            'Frios Cearense S/A',
            'Embutidos Maranhense ME',
            'Carnes Piauiense Ltda',
            'Doces Alagoana S/A',
            'Massas Sergipana ME',
            'Temperos Paraibana Ltda',
            'Conservas Potiguar S/A',
            'Hortifruti Capixaba ME',
            'Biscoitos Fluminense Ltda',
            'Condimentos Bonaerense S/A',
            'Bebidas Catarina ME',
            'Laticínios Gaúcha Premium Ltda',
            'Frigorífico Mato-Grossense S/A',
            'Distribuidora Goiana ME',
            'Comercial Tocantinense Ltda',
            'Alimentos Acreana S/A',
            'Cerealista Amapaense ME',
            'Grãos Roraimense Ltda',
            'Frios Paraense S/A',
            'Embutidos Amazonense ME',
            'Carnes Rondoniense Ltda',
            'Doces Sul-Mato-Grossense S/A',
        ];

        $inseridos = [];
        $cnpjBase = 10000000000100;

        foreach ($nomes as $i => $nome) {
            $cnpj = $this->formatarCnpj($cnpjBase + ($i * 37));
            $id = DB::table('fornecedores')->insertGetId([
                'nome'       => $nome,
                'cnpj'       => $cnpj,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inseridos[] = ['id' => $id, 'nome' => $nome];
        }

        $this->command->line("   → " . count($inseridos) . " fornecedores criados.");
        return $inseridos;
    }

    // ── Requisições ───────────────────────────────────────────────────────────

    private function seedRequisicoes(array $usuarios, array $fornecedores): void
    {
        $this->command->info('📋 Criando requisições...');

        $hoje     = Carbon::today();
        $motivos  = $this->construirPeso(self::PESO_MOTIVO_REQ);
        $lojasPeso = $this->construirPeso(self::PESO_LOJA);

        $bar = $this->command->getOutput()->createProgressBar(self::TOTAL_REQUISICOES);
        $bar->start();

        $lote = [];
        $auditoria = [];

        for ($i = 0; $i < self::TOTAL_REQUISICOES; $i++) {
            $diasAtras  = $this->distribuicaoDias(self::DIAS_HISTORICO);
            $data       = $hoje->copy()->subDays($diasAtras);
            $hora       = $this->horarioComercial();
            $createdAt  = $data->copy()->setTimeFromTimeString($hora);

            $user       = $usuarios[array_rand($usuarios)];
            $fornecedor = $fornecedores[array_rand($fornecedores)];
            $motivo     = $motivos[array_rand($motivos)];
            $loja       = $lojasPeso[array_rand($lojasPeso)];

            // Requisições mais antigas têm maior chance de estar atendidas
            $chanceAtendida = min(95, 40 + ($diasAtras * 0.8));
            $status = (rand(1, 100) <= $chanceAtendida) ? 'Atendida' : 'Pendente';

            $updatedAt = $createdAt->copy();
            if ($status === 'Atendida') {
                // Atendida algumas horas depois (ou no mesmo dia, ou no dia seguinte)
                $updatedAt->addMinutes(rand(20, 600));
            }

            $lote[] = [
                'numero_nota'   => $this->gerarNumeroNota(),
                'fornecedor_id' => $fornecedor['id'],
                'user_id'       => $user['id'],
                'loja'          => $loja,
                'motivo'        => $motivo,
                'observacao'    => rand(1, 3) === 1 ? $this->observacaoAleatoria() : null,
                'status'        => $status,
                'created_at'    => $createdAt,
                'updated_at'    => $updatedAt,
            ];

            // Auditoria de criação
            $auditoria[] = [
                'acao'             => 'criada',
                'user_id'          => $user['id'],
                'dados_anteriores' => null,
                'dados_novos'      => json_encode(['status' => 'Pendente', 'motivo' => $motivo]),
                'criado_em'        => $createdAt,
            ];

            // Auditoria de atendimento
            if ($status === 'Atendida') {
                $userAtendeu = $usuarios[array_rand($usuarios)];
                $auditoria[] = [
                    'acao'             => 'atendida',
                    'user_id'          => $userAtendeu['id'],
                    'dados_anteriores' => json_encode(['status' => 'Pendente']),
                    'dados_novos'      => json_encode(['status' => 'Atendida']),
                    'criado_em'        => $updatedAt,
                ];
            }

            if (count($lote) >= 50) {
                $ids = [];
                foreach ($lote as $row) {
                    $ids[] = DB::table('requisicoes')->insertGetId($row);
                }
                // Associa auditoria ao id correto
                $aud = array_splice($auditoria, 0, count($lote));
                $idIdx = 0;
                foreach ($aud as &$a) {
                    $a['requisicao_id'] = $ids[$idIdx];
                    if ($a['acao'] === 'atendida') {
                        // atendida compartilha o mesmo id do anterior
                    } else {
                        $idIdx++;
                    }
                }
                // Reprocessa para garantir ids corretos
                $this->inserirAuditoriasLote($lote, $aud, $usuarios);
                $lote = [];
                $auditoria = [];
                $bar->advance(50);
            }
        }

        // Resto
        if (!empty($lote)) {
            $this->inserirAuditoriasLote($lote, $auditoria, $usuarios);
            $bar->advance(count($lote));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->line("   → " . self::TOTAL_REQUISICOES . " requisições criadas.");
    }

    private function inserirAuditoriasLote(array $lote, array $_auditoria, array $usuarios): void
    {
        $audRows = [];
        foreach ($lote as $row) {
            $id = DB::table('requisicoes')->insertGetId($row);

            $audRows[] = [
                'requisicao_id'    => $id,
                'user_id'          => $row['user_id'],
                'acao'             => 'criada',
                'dados_anteriores' => null,
                'dados_novos'      => json_encode(['status' => 'Pendente', 'motivo' => $row['motivo']]),
                'criado_em'        => $row['created_at'],
            ];

            if ($row['status'] === 'Atendida') {
                $audRows[] = [
                    'requisicao_id'    => $id,
                    'user_id'          => $usuarios[array_rand($usuarios)]['id'],
                    'acao'             => 'atendida',
                    'dados_anteriores' => json_encode(['status' => 'Pendente']),
                    'dados_novos'      => json_encode(['status' => 'Atendida']),
                    'criado_em'        => $row['updated_at'],
                ];
            }
        }

        foreach (array_chunk($audRows, 100) as $chunk) {
            DB::table('requisicao_auditorias')->insert($chunk);
        }
    }

    // ── Cadastros ─────────────────────────────────────────────────────────────

    private function seedCadastros(array $usuarios, array $fornecedores): void
    {
        $this->command->info('📦 Criando cadastros...');

        $hoje = Carbon::today();

        $bar = $this->command->getOutput()->createProgressBar(self::TOTAL_CADASTROS);
        $bar->start();

        $lote = [];

        for ($i = 0; $i < self::TOTAL_CADASTROS; $i++) {
            $diasAtras  = $this->distribuicaoDias(self::DIAS_HISTORICO);
            $data       = $hoje->copy()->subDays($diasAtras);
            $hora       = $this->horarioComercial();
            $createdAt  = $data->copy()->setTimeFromTimeString($hora);

            $user       = $usuarios[array_rand($usuarios)];
            $fornecedor = $fornecedores[array_rand($fornecedores)];
            $motivo     = self::MOTIVOS_CAD[rand(0, 1)];
            $loja       = self::LOJAS[array_rand(self::LOJAS)];

            $chanceAtendida = min(95, 35 + ($diasAtras * 0.75));
            $status = (rand(1, 100) <= $chanceAtendida) ? 'Atendida' : 'Pendente';

            $updatedAt = ($status === 'Atendida')
                ? $createdAt->copy()->addMinutes(rand(30, 480))
                : $createdAt->copy();

            $lote[] = [
                'numero_nota'   => $this->gerarNumeroNota(),
                'fornecedor_id' => $fornecedor['id'],
                'user_id'       => $user['id'],
                'requisicao_id' => null,
                'loja'          => $loja,
                'motivo'        => $motivo,
                'observacao'    => rand(1, 4) === 1 ? $this->observacaoAleatoria() : null,
                'status'        => $status,
                'deleted_at'    => null,
                'created_at'    => $createdAt,
                'updated_at'    => $updatedAt,
            ];

            if (count($lote) >= 50) {
                DB::table('cadastros')->insert($lote);
                $lote = [];
                $bar->advance(50);
            }
        }

        if (!empty($lote)) {
            DB::table('cadastros')->insert($lote);
            $bar->advance(count($lote));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->line("   → " . self::TOTAL_CADASTROS . " cadastros criados.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Distribuição de dias com peso: dias recentes aparecem mais (curva exponencial suave).
     */
    private function distribuicaoDias(int $max): int
    {
        // Quanto mais recente, maior a chance
        $rand = mt_rand(0, 1000) / 1000;
        return (int) floor($max * pow($rand, 1.8));
    }

    /**
     * Horário comercial com distribuição realista:
     * pico pela manhã (08-11h) e tarde (13-16h), menos no almoço e fim do dia.
     */
    private function horarioComercial(): string
    {
        $faixas = [
            ['ini' => '07:30', 'fim' => '09:30', 'peso' => 20],
            ['ini' => '09:30', 'fim' => '12:00', 'peso' => 35],
            ['ini' => '12:00', 'fim' => '13:30', 'peso' => 8],
            ['ini' => '13:30', 'fim' => '16:00', 'peso' => 28],
            ['ini' => '16:00', 'fim' => '18:30', 'peso' => 9],
        ];

        $faixa = $this->construirPeso(array_column($faixas, 'peso', 'ini'));
        $chave = $faixa[array_rand($faixa)];
        $faixaSel = collect($faixas)->firstWhere('ini', $chave);

        $iniMin = $this->horaParaMinutos($faixaSel['ini']);
        $fimMin = $this->horaParaMinutos($faixaSel['fim']);
        $minutos = rand($iniMin, $fimMin);

        return sprintf('%02d:%02d:00', intdiv($minutos, 60), $minutos % 60);
    }

    private function horaParaMinutos(string $hora): int
    {
        [$h, $m] = explode(':', $hora);
        return (int)$h * 60 + (int)$m;
    }

    private function gerarNumeroNota(): string
    {
        return (string) rand(10000, 99999);
    }

    private function observacaoAleatoria(): string
    {
        $obs = [
            'Nota fiscal com divergência de preço',
            'Fornecedor solicitou urgência',
            'Produto chegou sem código de barras',
            'Conferir com o comprador',
            'Nota duplicada — verificar',
            'Aguardando retorno do fornecedor',
            'Produto com validade próxima',
            'Quantidade no pedido diferente da NF',
            'Nota emitida com CNPJ incorreto',
            'Substituição de mercadoria danificada',
            'Bonificação — sem custo',
            'Falta embalagem secundária',
            'Produto sem registro na tabela',
            'Devolução parcial aprovada',
            'Preço negociado não aplicado',
        ];
        return $obs[array_rand($obs)];
    }

    private function formatarCnpj(int $base): string
    {
        $s = str_pad((string)($base % 100000000000000), 14, '0', STR_PAD_LEFT);
        return substr($s, 0, 2) . '.' . substr($s, 2, 3) . '.' . substr($s, 5, 3) . '/' . substr($s, 8, 4) . '-' . substr($s, 12, 2);
    }

    /**
     * Constrói um array "pesado" para array_rand — repete cada chave N vezes conforme peso.
     */
    private function construirPeso(array $pesos): array
    {
        $resultado = [];
        foreach ($pesos as $chave => $peso) {
            for ($i = 0; $i < $peso; $i++) {
                $resultado[] = $chave;
            }
        }
        return $resultado;
    }
}
