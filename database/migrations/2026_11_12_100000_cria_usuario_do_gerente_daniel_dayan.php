<?php

use App\Models\User;
use App\Models\VendedorPerfil;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dá nome ao código 000054 — DANIEL DAYAN, o COD_GERENTE de 35 pessoas nos relatórios do
 * TOTVS — para ele aparecer no filtro "Direção" (DashboardScopeResolver::opcoesDiretores).
 *
 * O Daniel não usa o CRM e nunca teve usuário aqui; sem um `vendedor_perfis` com o código
 * dele, a diretoria existia nos dados e não tinha como ser escolhida.
 *
 * ⚠️ O usuário nasce INATIVO, com senha aleatória e e-mail em domínio `.invalid` (reservado,
 * não entrega): é um cadastro de nome, não uma conta. Se um dia ele for usar o sistema,
 * trocar e-mail e ativar pela tela Equipe.
 *
 * ⚠️ Não roda na suíte: um diretor a mais em todo banco de teste mudaria contagens de
 * testes que não têm nada a ver com isto.
 */
return new class extends Migration
{
    private const CODIGO = '000054';

    public function up(): void
    {
        if (app()->runningUnitTests() || VendedorPerfil::where('cod_vendedor', self::CODIGO)->exists()) {
            return;
        }

        Role::findOrCreate('diretor', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::create([
            'name' => 'DANIEL DAYAN',
            'display_name' => 'DANIEL DAYAN',
            'username' => 'daniel.dayan.'.self::CODIGO,
            'email' => 'sem-acesso.'.self::CODIGO.'@crm.invalid',
            'password' => Hash::make(Str::random(40)),
            'is_active' => false,
        ]);
        $user->assignRole('diretor');

        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => self::CODIGO]);
    }

    public function down(): void
    {
        User::where('username', 'daniel.dayan.'.self::CODIGO)->get()->each(function (User $user) {
            VendedorPerfil::where('user_id', $user->id)->delete();
            $user->delete();
        });
    }
};
