<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSyncStatus extends Model
{
    public $timestamps = false;

    protected $table = 'data_sync_status';

    protected $fillable = [
        'tabela',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
