<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Perfil "Venda interna" (ver User::PERFIS_CARTEIRA).
 *
 * Migration, e não só o RoleSeeder, porque produção não roda seeder no deploy — sem isto o
 * perfil não apareceria no dropdown da tela Equipe. Quem passa a conta para o perfil novo é
 * o admin, pela Equipe: a migration não mexe em usuário nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::findOrCreate('venda_interna', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'venda_interna')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
