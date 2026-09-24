<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\RedefinirSenhaNotification;
use App\Support\Uploads\Disco;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /** Janela de "online agora", mesma da usuario_presenca_minutos_online() do legado. */
    public const MINUTOS_ONLINE = 5;

    /**
     * Perfis que atendem uma carteira PRÓPRIA, pelo `cod_vendedor` do perfil.
     *
     * É a resposta única a "este usuário opera como vendedor?": escopo da Carteira, dos
     * Pedidos e dos blocos do Painel, dropdown de vendedores do gestor, ranking de metas e
     * Visão do Gestor leem daqui. Espelhada em `resources/js/constants/perfis.js`
     * (`PERFIS_CARTEIRA`) — mudou aqui, muda lá.
     *
     * `venda_interna` (2026-09-24) é a conta compartilhada do time interno
     * (comercial@autopel.com, código 010617 — o mesmo que recebe os leads do site sem dono).
     * Estava como `assistente`, que não tem carteira: via o Painel vazio e nenhum cliente.
     * É perfil próprio, e não `vendedor`, para poder divergir depois sem mexer em quem vende.
     *
     * ⚠️ Supervisor NÃO entra: ele também tem carteira, mas o escopo dele é a equipe (com o
     * alternador de modo), e cada consumidor decide se o inclui.
     */
    public const PERFIS_CARTEIRA = ['vendedor', 'representante', 'venda_interna'];

    /**
     * Perfis que só existem no CRM-V2, sem equivalente no `USUARIOS.PERFIL` do legado.
     * O `legado:import-usuarios` não sobrescreve quem está num deles — senão a próxima
     * importação devolveria a venda interna para `assistente`.
     */
    public const PERFIS_SO_DO_CRM = ['venda_interna'];

    /**
     * Usuários com algum dos perfis — pelo NOME, dentro do próprio `whereHas`.
     *
     * ⚠️ Existe no lugar do `User::role([...])` do spatie para listas de perfis: aquele
     * escopo faz um `Role::findByName()` — uma query — POR NOME antes de montar o filtro.
     * Com `PERFIS_CARTEIRA` passando de dois para três nomes, o Painel do admin ganhou 3
     * queries e estourou o teto do `OrcamentoDeQueriesTest`. Aqui o custo não cresce com a
     * lista. De quebra, nome que não existe só não casa, em vez de estourar
     * `RoleDoesNotExist` (produção antes da migration do perfil, por exemplo).
     *
     * @param  list<string>  $perfis
     */
    public function scopeComPerfil(Builder $query, array $perfis): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q
            ->whereIn('roles.name', $perfis)
            ->where('roles.guard_name', 'web'));
    }

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

    public function scopeOnlineAgora(Builder $query): Builder
    {
        return $query->where('last_activity_at', '>=', now()->subMinutes(self::MINUTOS_ONLINE));
    }

    public function estaOnline(): bool
    {
        return $this->last_activity_at?->gt(now()->subMinutes(self::MINUTOS_ONLINE)) ?? false;
    }

    /**
     * URL pública da foto (só uploads do v2 em storage/public/perfis).
     * Paths do legado (assets/img/perfis/...) não existem neste app.
     */
    /**
     * Troca a notificação de reset padrão do Laravel (inglês, assinada "Laravel")
     * pela nossa, em pt_BR. Ver App\Notifications\RedefinirSenhaNotification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new RedefinirSenhaNotification($token));
    }

    public function getFotoUrlAttribute(): ?string
    {
        if (! $this->foto_perfil) {
            return null;
        }

        if (! str_starts_with($this->foto_perfil, 'perfis/')) {
            // Valor herdado do legado (assets/img/perfis/...) — não é caminho no nosso
            // storage, então não há URL a gerar.
            return null;
        }

        /*
         * ⚠️ Sem `exists()` aqui, de propósito.
         *
         * A versão anterior checava a existência antes de gerar a URL, o que era barato
         * com disco local. Com o S3 isso vira uma chamada de REDE por usuário renderizado
         * — e esta accessor roda no layout, em toda página. Uma foto apagada por fora vira
         * um 404 na tag <img>, que custa infinitamente menos que uma ida ao S3 a cada
         * carregamento.
         */
        return Disco::urlUpload($this->foto_perfil);
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
