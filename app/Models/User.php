<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'username',
        'email',
        'password',
        'tipo_usuario',
        'is_active',
        'telefone',
        'estado',
        'foto_perfil',
        'sidebar_color',
        'secondary_color',
        'navbar_template',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'foto_url',
    ];

    public function vendedorPerfil(): HasOne
    {
        return $this->hasOne(VendedorPerfil::class);
    }

    /**
     * URL pública da foto (só uploads do v2 em storage/public/perfis).
     * Paths do legado (assets/img/perfis/...) não existem neste app.
     */
    public function getFotoUrlAttribute(): ?string
    {
        if (! $this->foto_perfil) {
            return null;
        }

        if (str_starts_with($this->foto_perfil, 'perfis/')
            && Storage::disk('public')->exists($this->foto_perfil)) {
            return Storage::disk('public')->url($this->foto_perfil);
        }

        return null;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }
}
