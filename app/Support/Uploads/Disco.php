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

    public static function nomeExports(): string
    {
        return config('filesystems.exports', 'local');
    }
}
