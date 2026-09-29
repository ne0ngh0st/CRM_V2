<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Um e-mail que saiu (ou tentou sair) pelo SMTP. Quem escreve é só
 * `App\Services\Email\RegistroDeEmails`; quem lê é a tela admin `/emails`.
 *
 * Só envelope, nunca corpo — ver a migration.
 */
class EmailEnviado extends Model
{
    use MassPrunable;

    /** Log de conferência, não arquivo: 90 dias bastam para responder "saiu?". */
    public const DIAS_RETENCAO = 90;

    public const UPDATED_AT = null;

    protected $table = 'emails_enviados';

    protected $fillable = ['status', 'assunto', 'remetente', 'para', 'cc', 'bcc', 'anexos', 'origem', 'erro'];

    protected function casts(): array
    {
        return ['anexos' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::DIAS_RETENCAO));
    }
}
