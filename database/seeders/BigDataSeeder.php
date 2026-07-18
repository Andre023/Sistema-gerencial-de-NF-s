<?php

namespace Database\Seeders;

use App\Models\Card;
use App\Models\Nota;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Dados de demonstração do fluxo real: notas com cards de divergência.
 *
 * Preserva usuários que não sejam @sistema.com (contas reais).
 */
class BigDataSeeder extends Seeder
{
    const DIAS_HISTORICO = 60;
    const TOTAL_NOTAS    = 400;

    const LOJAS = [1, 2, 3, 9, 11, 12];

    // Peso por tipo de divergência (cadastro é o campeão no fluxo real)
    const PESO_TIPO = [
        'cadastro'   => 40,
        'custo'      => 25,
        'regra'      => 20,
        'quantidade' => 15,
    ];

    const PESO_LOJA = [1 => 25, 2 => 20, 3 => 18, 9 => 15, 11 => 12, 12 => 10];

    public function run(): void
    {
        $this->command->info('🌱 Iniciando BigDataSeeder (fluxo de notas)...');
        $this->command->newLine();

        Schema::disableForeignKeyConstraints();

        DB::table('comentarios')->delete();
        DB::table('cards')->delete();
        DB::table('notas')->delete();
        DB::table('fornecedores')->delete();
        DB::table('users')->where('email', 'like', '%@sistema.com')->delete();

        Schema::enableForeignKeyConstraints();

        $usuarios     = $this->seedUsuarios();
        $fornecedores = $this->seedFornecedores();
        $this->seedNotas($usuarios, $fornecedores);

        $this->command->newLine();
        $this->command->info('✅ BigDataSeeder concluído!');
        $this->command->newLine();
        $this->command->line('  📧 Usuários de demonstração (senha: password):');
        foreach ($usuarios as $papel => $lista) {
            foreach ($lista as $u) {
                $this->command->line("     {$u['email']}  →  {$papel}");
            }
        }
        $this->command->newLine();
    }

    // ── Usuários por função ───────────────────────────────────────────────────

    private function seedUsuarios(): array
    {
        $this->command->info('👤 Criando usuários por função...');

        $porPapel = [
            User::ROLE_ADMIN       => [['name' => 'Admin Sistema', 'email' => 'admin@sistema.com']],
            User::ROLE_RECEBIMENTO => [
                ['name' => 'Ana Paula',    'email' => 'ana@sistema.com'],
                ['name' => 'Mariana Costa', 'email' => 'mariana@sistema.com'],
            ],
            User::ROLE_PRE_LOTE => [
                ['name' => 'Fernanda Lima', 'email' => 'fernanda@sistema.com'],
                ['name' => 'João Vitor',    'email' => 'joao@sistema.com'],
            ],
            User::ROLE_COMPRAS => [
                ['name' => 'Carlos Mendes', 'email' => 'carlos@sistema.com'],
            ],
        ];

        $criados = [];
        foreach ($porPapel as $papel => $lista) {
            foreach ($lista as $u) {
                $id = DB::table('users')->insertGetId([
                    'name'              => $u['name'],
                    'email'             => $u['email'],
                    'role'              => $papel,
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $criados[$papel][] = array_merge($u, ['id' => $id]);
            }
        }

        $total = array_sum(array_map('count', $criados));
        $this->command->line("   → {$total} usuários criados.");

        return $criados;
    }

    // ── Fornecedores ──────────────────────────────────────────────────────────

    private function seedFornecedores(): array
    {
        $this->command->info('🏭 Criando fornecedores...');

        $nomes = [
            'Distribuidora Alfa Ltda', 'Comercial Beta S/A', 'Indústria Gama ME',
            'Atacadão Delta Distribuidora', 'Frigorífico Epsilon Ltda', 'Laticínios Zeta S/A',
            'Cerealista Eta Comércio', 'Panificadora Theta ME', 'Bebidas Iota Ltda',
            'Hortifruti Kappa S/A', 'Carnes Nobre Lambda Ltda', 'Mercearia Mu Distribuidora',
            'Alimentos Nu S/A', 'Doces Xi ME', 'Biscoitos Omicron Ltda', 'Massas Pi Indústria',
            'Grãos Rho Comércio', 'Temperos Sigma ME', 'Conservas Tau Ltda', 'Bebidas Upsilon S/A',
            'Laticínios Phi Comércio', 'Rações Chi Distribuidora', 'Embutidos Psi Ltda',
            'Frios Omega S/A', 'Distribuidora Norte ME', 'Atacado Sul Ltda', 'Comércio Leste S/A',
            'Produtos Oeste ME', 'Alimentos Central Ltda', 'Frigorífico Novo Mundo S/A',
            'Indústria Premium ME', 'Distribuidora Top Ltda', 'Comercial Plus S/A',
            'Atacadista Max ME', 'Fornecedor Mix Ltda', 'Alimentos Select S/A',
            'Cerealista Nacional ME', 'Bebidas Import Ltda', 'Frios Brasil S/A',
            'Laticínios Primor ME', 'Grãos e Farinhas Ltda', 'Condimentos Real S/A',
            'Doces & Balas ME', 'Massas Italianas Ltda', 'Embutidos Chef S/A',
            'Conservas Gourmet ME', 'Hortifruti Express Ltda', 'Carnes Prime S/A',
            'Biscoitos Alegria ME', 'Temperos do Sul Ltda',
        ];

        $inseridos = [];
        $cnpjBase  = 10000000000100;

        foreach ($nomes as $i => $nome) {
            $id = DB::table('fornecedores')->insertGetId([
                'nome'       => mb_strtoupper($nome),
                'cnpj'       => $this->formatarCnpj($cnpjBase + ($i * 37)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inseridos[] = $id;
        }

        $this->command->line('   → ' . count($inseridos) . ' fornecedores criados.');

        return $inseridos;
    }

    // ── Notas com ciclo de vida ───────────────────────────────────────────────

    private function seedNotas(array $usuarios, array $fornecedores): void
    {
        $this->command->info('📋 Criando notas e cards...');

        $hoje      = Carbon::today();
        $tiposPeso = $this->construirPeso(self::PESO_TIPO);
        $lojasPeso = $this->construirPeso(self::PESO_LOJA);

        $lancadores = array_merge($usuarios[User::ROLE_RECEBIMENTO], $usuarios[User::ROLE_PRE_LOTE]);
        $preLote    = $usuarios[User::ROLE_PRE_LOTE];
        $compras    = $usuarios[User::ROLE_COMPRAS];

        $bar = $this->command->getOutput()->createProgressBar(self::TOTAL_NOTAS);
        $bar->start();

        for ($i = 0; $i < self::TOTAL_NOTAS; $i++) {
            $diasAtras = $this->distribuicaoDias(self::DIAS_HISTORICO);
            $createdAt = $hoje->copy()->subDays($diasAtras)
                ->setTimeFromTimeString($this->horarioComercial());

            $origem   = rand(1, 100) <= 85 ? 'recebimento' : 'pre_lote';
            $lancador = $lancadores[array_rand($lancadores)];

            $notaId = DB::table('notas')->insertGetId([
                'numero_nota'   => (string) rand(10000, 9999999),
                'fornecedor_id' => $fornecedores[array_rand($fornecedores)],
                'user_id'       => $lancador['id'],
                'loja'          => $lojasPeso[array_rand($lojasPeso)],
                'origem'        => $origem,
                'observacao'    => rand(1, 4) === 1 ? $this->observacaoAleatoria() : null,
                'created_at'    => $createdAt,
                'updated_at'    => $createdAt,
            ]);

            // ~45% das notas passam limpas; o resto ganha 1-2 cards (3 às vezes)
            $qtdCards = rand(1, 100) <= 45 ? 0 : (rand(1, 100) <= 70 ? 1 : (rand(1, 100) <= 85 ? 2 : 3));

            $tiposUsados = [];
            $ultimoEvento = $createdAt->copy();
            $todosResolvidos = true;

            for ($c = 0; $c < $qtdCards; $c++) {
                $tipo = $tiposPeso[array_rand($tiposPeso)];
                if (in_array($tipo, $tiposUsados, true)) continue; // um card ativo por tipo
                $tiposUsados[] = $tipo;

                $abertoEm = $createdAt->copy()->addMinutes(rand(15, 240));
                $analista = $preLote[array_rand($preLote)];

                // Quanto mais antiga a nota, mais avançado o ciclo do card
                $chanceResolvido = min(95, 30 + ($diasAtras * 2.5));
                $sorte = rand(1, 100);

                $card = [
                    'nota_id'    => $notaId,
                    'tipo'       => $tipo,
                    'detalhe'    => rand(1, 3) === 1 ? $this->detalheAleatorio($tipo) : null,
                    'aberto_por' => $analista['id'],
                    'created_at' => $abertoEm,
                    'updated_at' => $abertoEm,
                    'status'     => Card::STATUS_ABERTO,
                    'reaberturas' => 0,
                ];

                if ($sorte <= $chanceResolvido) {
                    // resolvido — regra é fechada pelo pré-lote; o resto, corrigido por compras
                    $resolvidoEm = $abertoEm->copy()->addMinutes(rand(60, 1440));
                    $card['status']      = Card::STATUS_RESOLVIDO;
                    $card['reaberturas'] = rand(1, 100) <= 12 ? 1 : 0;
                    $card['updated_at']  = $resolvidoEm;

                    if ($tipo === 'regra') {
                        $card['resolvido_por'] = $preLote[array_rand($preLote)]['id'];
                        $card['resolvido_em']  = $resolvidoEm;
                    } else {
                        $card['corrigido_por'] = $compras[array_rand($compras)]['id'];
                        $card['corrigido_em']  = $resolvidoEm;
                    }

                    $ultimoEvento = $ultimoEvento->max($resolvidoEm);
                } else {
                    // ainda aberto — aguardando quem corrige
                    $todosResolvidos = false;
                }

                DB::table('cards')->insert($card);
            }

            // Liberação: nota sem cards ativos tem chance de já ter sido liberada
            $chanceLiberada = min(95, 40 + ($diasAtras * 2));
            if ($todosResolvidos && rand(1, 100) <= $chanceLiberada) {
                $liberadaEm = $ultimoEvento->copy()->addMinutes(rand(10, 300));
                $analista   = $preLote[array_rand($preLote)];
                DB::table('notas')->where('id', $notaId)->update([
                    'liberada_por' => $analista['id'],
                    'liberada_em'  => $liberadaEm,
                    'updated_at'   => $liberadaEm,
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->line('   → ' . self::TOTAL_NOTAS . ' notas criadas.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function distribuicaoDias(int $max): int
    {
        $rand = mt_rand(0, 1000) / 1000;
        return (int) floor($max * pow($rand, 1.8));
    }

    private function horarioComercial(): string
    {
        $faixas = [
            ['ini' => 7 * 60 + 30, 'fim' => 9 * 60 + 30, 'peso' => 20],
            ['ini' => 9 * 60 + 30, 'fim' => 12 * 60, 'peso' => 35],
            ['ini' => 12 * 60, 'fim' => 13 * 60 + 30, 'peso' => 8],
            ['ini' => 13 * 60 + 30, 'fim' => 16 * 60, 'peso' => 28],
            ['ini' => 16 * 60, 'fim' => 18 * 60 + 30, 'peso' => 9],
        ];

        $pool = [];
        foreach ($faixas as $idx => $f) {
            for ($i = 0; $i < $f['peso']; $i++) $pool[] = $idx;
        }
        $faixa = $faixas[$pool[array_rand($pool)]];
        $min   = rand($faixa['ini'], $faixa['fim']);

        return sprintf('%02d:%02d:00', intdiv($min, 60), $min % 60);
    }

    private function observacaoAleatoria(): string
    {
        $obs = [
            'Fornecedor solicitou urgência',
            'Conferir com o comprador',
            'Nota duplicada — verificar',
            'Aguardando retorno do fornecedor',
            'Produto com validade próxima',
            'Bonificação — sem custo',
            'Devolução parcial aprovada',
        ];
        return $obs[array_rand($obs)];
    }

    private function detalheAleatorio(string $tipo): string
    {
        $detalhes = [
            'cadastro'   => ['Item sem cadastro no ERP', 'Código de barras não registrado', 'Produto novo — cadastrar'],
            'regra'      => ['Regra fiscal divergente', 'NCM incorreto na nota', 'CFOP não confere'],
            'custo'      => ['Custo diferente do negociado', 'Preço tabela desatualizado', 'Desconto não aplicado'],
            'quantidade' => ['Quantidade da NF difere do pedido', 'Volume a menos na carga', 'Item faltando'],
        ];
        $lista = $detalhes[$tipo];
        return $lista[array_rand($lista)];
    }

    private function formatarCnpj(int $base): string
    {
        $s = str_pad((string) ($base % 100000000000000), 14, '0', STR_PAD_LEFT);
        return substr($s, 0, 2) . '.' . substr($s, 2, 3) . '.' . substr($s, 5, 3) . '/' . substr($s, 8, 4) . '-' . substr($s, 12, 2);
    }

    private function construirPeso(array $pesos): array
    {
        $resultado = [];
        foreach ($pesos as $chave => $peso) {
            for ($i = 0; $i < $peso; $i++) $resultado[] = $chave;
        }
        return $resultado;
    }
}
