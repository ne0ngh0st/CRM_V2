<?php

namespace App\Support\Uploads;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Resolve o disco certo para cada tipo de arquivo.
 *
 * Existe para que a escolha "onde este arquivo mora" fique num lugar só (Regra de ouro
 * nº 8). Antes, cada ponto de upload chamava `Storage::disk('public')` direto — e migrar
 * para o S3 significaria caçar todas as chamadas e torcer para não esquecer nenhuma.
 *
 * ⚠️ A migração é obrigatória com dois app nodes: arquivo gravado no disco local do nó A
 * não existe no nó B. A imagem quebra em ~50% dos carregamentos e o link de download da
 * planilha falha metade das vezes.
 *
 * Em dev os dois apontam para discos locais; em produção, para o S3.
 * Ver `config/filesystems.php` e docs/deploy-aws.md §5.4.
 */
class Disco
{
    /** Conteúdo servido ao navegador: foto de perfil, imagem de faca. */
    public static function uploads(): Filesystem
    {
        return Storage::disk(self::nomeUploads());
    }

    /**
     * Planilhas geradas em segundo plano.
     *
     * ⚠️ NUNCA público. O download passa pelo ExportacaoController, que confere se o
     * arquivo pertence a quem pediu — um .xlsx da Carteira contém a base inteira de
     * clientes de alguém.
     */
    public static function exports(): Filesystem
    {
        return Storage::disk(self::nomeExports());
    }

    public static function nomeUploads(): string
    {
        return config('filesystems.uploads', 'public');
    }

    /**
     * URL para o navegador exibir um arquivo de upload.
     *
     * ⚠️ NÃO troque por `Disco::uploads()->url(...)`. Em produção o disco é o S3 e o
     * bucket bloqueia acesso público — obrigatoriamente, porque o mesmo bucket guarda as
     * planilhas de `exports/`, e cada uma contém a carteira inteira de um vendedor. Uma
     * URL pública do S3 responde 403, e o sintoma é imagem quebrada sem erro nenhum no
     * log do Laravel: para o PHP, gerar a URL funcionou. Descoberto em 2026-08-28, ao
     * ativar o S3 em produção.
     *
     * Em disco local (dev) não há o que assinar, então cai no `url()` normal.
     *
     * ⚠️ Efeito colateral aceito: a URL assinada muda a cada renderização, então o
     * navegador não reaproveita a imagem entre carregamentos de página. Hoje isso pesa
     * pouco (as 166 imagens do catálogo de facas são assets versionados em
     * `public/images/`, que nem passam por aqui — só upload de tela chega neste método).
     * Se um dia pesar, a saída é CloudFront com Origin Access Control na frente do
     * bucket, servindo URL estável e cacheável sem abrir o bucket.
     */
    public static function urlUpload(string $caminho): string
    {
        $disco = self::nomeUploads();

        if (config("filesystems.disks.{$disco}.driver") === 's3') {
            return self::uploads()->temporaryUrl($caminho, now()->addHour());
        }

        return self::uploads()->url($caminho);
    }

    public static function nomeExports(): string
    {
        return config('filesystems.exports', 'local');
    }
}
