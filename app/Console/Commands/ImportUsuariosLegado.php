<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VendedorPerfil;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;

class ImportUsuariosLegado extends Command
{
    /**
     * Perfis fora do escopo comercial do CRM-V2 (SAC, Licitação) ficam de fora.
     */
    private const PERFIS_ESCOPO = ['ADMIN', 'ASSISTENTE', 'DIRETOR', 'REPRESENTANTE', 'SUPERVISOR', 'VENDEDOR'];

    protected $signature = 'legado:import-usuarios';

    protected $description = 'Import pontual (somente leitura) da tabela USUARIOS de produção pro escopo comercial do CRM-V2';

    public function handle(): int
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('LEGADO_DB_HOST'), env('LEGADO_DB_DATABASE')),
            env('LEGADO_DB_USERNAME'),
            env('LEGADO_DB_PASSWORD'),
        );

        $placeholders = implode(',', array_fill(0, count(self::PERFIS_ESCOPO), '?'));
        $stmt = $pdo->prepare("SELECT * FROM USUARIOS WHERE UPPER(PERFIL) IN ($placeholders)");
        $stmt->execute(self::PERFIS_ESCOPO);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->info(sprintf('%d usuários encontrados no escopo comercial.', count($rows)));

        $criados = 0;
        $perfis = [];

        DB::transaction(function () use ($rows, &$criados, &$perfis) {
            foreach ($rows as $row) {
                $user = User::updateOrCreate(
                    ['email' => $row['EMAIL']],
                    [
                        'name' => $row['NOME_COMPLETO'],
                        'display_name' => $row['NOME_EXIBICAO'],
                        'username' => $row['USERNAME'],
                        'password' => Hash::make(Str::random(40)),
                        'email_verified_at' => now(),
                        'tipo_usuario' => $row['TIPO_USUARIO'] ?: 'INTERNO',
                        'is_active' => (bool) $row['ATIVO'],
                        'last_login_at' => $row['ULTIMO_LOGIN'],
                        'last_activity_at' => $row['ULTIMA_ATIVIDADE'],
                        'telefone' => $row['TELEFONE'],
                        'estado' => $row['ESTADO'],
                        'foto_perfil' => $row['FOTO_PERFIL'],
                        'sidebar_color' => $row['SIDEBAR_COLOR'] ?: '#1a237e',
                        'secondary_color' => $row['SECONDARY_COLOR'] ?: '#ff8f00',
                        'navbar_template' => $row['NAVBAR_TEMPLATE'] ?: 'default',
                    ]
                );

                $role = strtolower($row['PERFIL']);
                $user->syncRoles([$role]);
                $perfis[$role] = ($perfis[$role] ?? 0) + 1;

                if (! empty($row['COD_VENDEDOR'])) {
                    VendedorPerfil::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'cod_vendedor' => trim($row['COD_VENDEDOR']),
                            'cod_super' => $row['COD_SUPER'] !== null ? trim($row['COD_SUPER']) : null,
                            'cod_gerente' => $row['COD_GERENTE'] !== null ? str_pad((string) (int) $row['COD_GERENTE'], 6, '0', STR_PAD_LEFT) : null,
                            'segmento' => $row['SEGMENTO'],
                            'equipe_rep' => $row['EQUIPE_REP'],
                        ]
                    );
                }

                $criados++;
            }
        });

        $this->info("Importados/atualizados: {$criados}");
        foreach ($perfis as $perfil => $qtd) {
            $this->line("  {$perfil}: {$qtd}");
        }

        return self::SUCCESS;
    }
}
