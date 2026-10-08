<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissão para entrar na Visão Diretor sem ter o perfil diretor (ver o gate
 * `ver-visao-diretor` no AppServiceProvider). Só CRIA a permissão. Dar a alguém é
 * decisão de pessoa, não de migration:
 *
 *   php artisan tinker --execute="App\Models\User::where('email','x@autopel.com')->first()->givePermissionTo('ver-visao-diretor');"
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('ver-visao-diretor', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'ver-visao-diretor')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
