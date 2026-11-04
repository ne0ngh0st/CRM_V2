<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Perfil "Cadastro" (ver User::PERFIL_CADASTRO): quem só pede cadastro de bobina,
 * etiqueta e cliente — gente de fora do comercial, como a licitação.
 *
 * Migration, e não só o RoleSeeder, porque produção não roda seeder no deploy. Quem passa
 * a conta para o perfil é o admin, pela Equipe: a migration não mexe em usuário nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::findOrCreate('cadastro', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'cadastro')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
