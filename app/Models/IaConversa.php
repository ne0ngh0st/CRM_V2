<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IaConversa extends Model
{
    protected $table = 'ia_conversas';

    protected $fillable = ['user_id', 'admin_simulando_id', 'titulo', 'modelo'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(IaMensagem::class, 'conversa_id')->orderBy('id');
    }
}
