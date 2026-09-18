<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo anexado a uma publicação da intranet.
 *
 * `caminho` é o caminho no DISCO DE UPLOADS (`intranet/<uuid>.pdf`), nunca uma URL: a URL
 * muda conforme o disco (local em dev, S3 privado em produção) e é derivada na leitura.
 */
class IntranetAnexo extends Model
{
    protected $table = 'intranet_anexos';

    /**
     * Tipos aceitos, MIME detectado no servidor → extensão gravada.
     *
     * ⚠️ A extensão vem DAQUI, nunca do nome que o navegador mandou — mesmo cuidado do
     * `ProfileController::updateFoto` e do Catálogo de Facas, que já tiveram essa correção
     * de segurança. Um `.php` renomeado para `.pdf` é detectado como texto e recusado.
     *
     * Os três do Office aparecem com dois MIMEs porque o `finfo` do PHP às vezes devolve só
     * o contêiner zip do OOXML, conforme a versão da libmagic.
     */
    public const TIPOS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    /** Extensões que o OOXML pode ter quando o `finfo` só enxerga o zip. */
    public const EXTENSOES_OFFICE = ['docx', 'xlsx', 'pptx'];

    /**
     * 15 MB por arquivo. ⚠️ O teto real é o de PRODUÇÃO: `post_max_size` e
     * `client_max_body_size` de 16 MB em `infra/provisionar-servidor.sh`. Subir este número
     * sem subir aqueles dá 413 do nginx antes de o Laravel ver a requisição — a tela recebe
     * um erro sem mensagem nenhuma.
     */
    public const TAMANHO_MAXIMO_KB = 15360;

    protected $fillable = [
        'publicacao_id',
        'nome_original',
        'caminho',
        'mime',
        'tamanho',
        'ordem',
    ];

    public function publicacao(): BelongsTo
    {
        return $this->belongsTo(IntranetPublicacao::class, 'publicacao_id');
    }

    public function ehImagem(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
