<?php

namespace App\Models;

use App\Support\CartaCampanha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Chave/valor do sistema — o que o admin liga, desliga ou reescreve sem deploy.
 *
 * A leitura passa por cache porque a chave `campanha_ativa` é consultada em
 * TODA requisição (o menu precisa saber se mostra a aba). Sem cache seria uma
 * consulta a mais por página só para responder "sim" ou "não".
 */
class Configuracao extends Model
{
    protected $table = 'configuracoes';

    protected $primaryKey = 'chave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['chave', 'valor'];

    /** A campanha está no ar? Desligada, a aba some do menu de todo mundo. */
    public const CAMPANHA_ATIVA = 'campanha_ativa';

    /** O esqueleto que todo comprador vê antes de salvar o dele. */
    public const CAMPANHA_TEXTO_PADRAO = 'campanha_texto_padrao';

    /** De onde veio a base de faturamento: arquivo, data, quem enviou, linhas. */
    public const CAMPANHA_BASE = 'campanha_base';

    public static function obter(string $chave, ?string $padrao = null): ?string
    {
        $valor = Cache::rememberForever(
            self::chaveDeCache($chave),
            fn() => static::query()->whereKey($chave)->value('valor') ?? '',
        );

        return $valor === '' ? $padrao : $valor;
    }

    public static function definir(string $chave, ?string $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);

        Cache::forget(self::chaveDeCache($chave));
    }

    // ─── Campanha de aniversário ────────────────────────────────────────────────

    /**
     * Começa DESLIGADA: a aba só aparece depois que o admin liga. Assim o
     * deploy não estreia uma tela no menu de ninguém sem alguém decidir.
     */
    public static function campanhaAtiva(): bool
    {
        return static::obter(self::CAMPANHA_ATIVA, '0') === '1';
    }

    public static function definirCampanhaAtiva(bool $ativa): void
    {
        static::definir(self::CAMPANHA_ATIVA, $ativa ? '1' : '0');
    }

    /** O texto padrão da carta — o de fábrica até o admin escrever o dele. */
    public static function campanhaTextoPadrao(): string
    {
        return static::obter(self::CAMPANHA_TEXTO_PADRAO, CartaCampanha::TEXTO_DE_FABRICA);
    }

    private static function chaveDeCache(string $chave): string
    {
        return "configuracao:{$chave}";
    }
}
