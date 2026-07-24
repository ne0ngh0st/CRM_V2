<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Perfis comerciais do legado (escopo do CRM v2 = só comercial;
     * SAC e licitação ficam fora, cada um com seu próprio sistema).
     */
    private const ROLES = [
        'representante',
        'vendedor',
        'supervisor',
        'admin',
        'assistente',
        'diretor',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
