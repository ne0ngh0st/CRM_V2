<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Legado\LegadoConexao;
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
        $pdo = LegadoConexao::pdo();

        $placeholders = implode(',', array_fill(0, count(self::PERFIS_ESCOPO), '?'));
        $stmt = $pdo->prepare("SELECT * FROM USUARIOS WHERE UPPER(PERFIL) IN ($placeholders)");
        $stmt->execute(self::PERFIS_ESCOPO);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->info(sprintf('%d usuários encontrados no escopo comercial.', count($rows)));

        $criados = 0;
        $perfis = [];

        DB::transaction(function () use ($rows, &$criados, &$perfis) {
            foreach ($rows as $row) {
                $user = User::firstOrNew(['email' => $row['EMAIL']]);

                /*
                 * ⚠️ Campos de PRIMEIRA CARGA — só entram quando o usuário ainda não existe.
                 *
                 * Reimportar é rotina (o espelho do legado é atualizado periodicamente), e
                 * tudo que estiver aqui seria SOBRESCRITO a cada rodada. Estes campos são
                 * do CRM-V2 depois do primeiro import, não do legado:
                 *
                 * - password: é gerada aleatória de propósito (nunca migramos hash do legado).
                 *   Estando no update, reimportar derrubava o acesso de TODOS os usuários de
                 *   uma vez — em produção com beta testers, sem "esqueci minha senha" (SES não
                 *   configurado), isso é perda total de acesso.
                 * - display_name, telefone, foto_perfil: editáveis pelo próprio usuário em
                 *   /profile (ProfileUpdateRequest + ProfileController::updateFoto).
                 * - last_login_at, last_activity_at: NÃO entram nem na primeira carga.
                 *   Quem escreve é o CRM-V2 (o login e o middleware RegistrarAtividade);
                 *   o valor do legado é a atividade no sistema ANTIGO, e importá-lo faria
                 *   a badge "online agora" da Equipe mentir logo depois de um import.
                 */
                if (! $user->exists) {
                    $user->fill([
                        'password' => Hash::make(Str::random(40)),
                        'email_verified_at' => now(),
                        'display_name' => $row['NOME_EXIBICAO'],
                        'telefone' => $row['TELEFONE'],
                        'foto_perfil' => $row['FOTO_PERFIL'],
                    ]);
                }

                // Campos que o legado continua sendo dono — atualizados em toda rodada.
                $user->fill([
                    'name' => $row['NOME_COMPLETO'],
                    'username' => $row['USERNAME'],
                    'tipo_usuario' => $row['TIPO_USUARIO'] ?: 'INTERNO',
                    'is_active' => (bool) $row['ATIVO'],
                    'estado' => $row['ESTADO'],
                    'sidebar_color' => $row['SIDEBAR_COLOR'] ?: '#1a237e',
                    'secondary_color' => $row['SECONDARY_COLOR'] ?: '#ff8f00',
                    'navbar_template' => $row['NAVBAR_TEMPLATE'] ?: 'default',
                ])->save();

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
