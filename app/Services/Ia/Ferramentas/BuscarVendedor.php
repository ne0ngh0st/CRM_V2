<?php

namespace App\Services\Ia\Ferramentas;

use App\Models\User;
use App\Services\Equipe\EquipeScopeResolver;
use App\Services\Ia\ContextoIa;
use App\Services\Ia\Ferramenta;

/**
 * Código de uma pessoa da Autopel, pelo nome.
 *
 * O escopo é o da tela Equipe (`EquipeScopeResolver`): admin e diretor veem todo mundo,
 * supervisor vê a própria equipe, e os demais perfis não consultam a equipe. A mesma
 * frase de "não achei" cobre "não existe" e "existe, mas não é da sua equipe".
 */
final class BuscarVendedor implements Ferramenta
{
    private const LIMITE = 10;

    /** @var array<string, string> */
    private const PERFIS = [
        'admin' => 'admin',
        'diretor' => 'diretor',
        'supervisor' => 'supervisor',
        'vendedor' => 'vendedor',
        'representante' => 'representante',
        'venda_interna' => 'venda interna',
        'assistente' => 'assistente',
    ];

    public function __construct(private readonly EquipeScopeResolver $equipe) {}

    public function nome(): string
    {
        return 'buscar_vendedor';
    }

    public function rotuloProgresso(): string
    {
        return 'Procurando a pessoa na equipe…';
    }

    public function descricao(): string
    {
        return 'Procura uma pessoa da Autopel (vendedor, representante, supervisor, venda '
            .'interna) pelo nome e devolve o código de vendedor dela no TOTVS. Use quando '
            .'perguntarem "qual o código da Fulana", "de quem é esse código" sobre uma '
            .'PESSOA, ou quando o usuário disser que o pedido é de alguém da equipe. Não '
            .'use para empresa: cliente é buscar_cliente ou quem_cuida_do_cliente.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nome' => ['type' => 'string', 'description' => 'Nome da pessoa, ou parte dele (mínimo 3 caracteres).'],
            ],
            'required' => ['nome'],
            'additionalProperties' => false,
        ];
    }

    public function executar(ContextoIa $contexto, array $entrada): array
    {
        $nome = trim((string) ($entrada['nome'] ?? ''));
        $palavras = array_values(array_filter(preg_split('/\s+/u', $nome) ?: []));

        if (mb_strlen($nome) < 3) {
            return ['pessoas' => [], 'mensagem' => 'Informe pelo menos 3 letras do nome.'];
        }

        $codigos = $this->equipe->codigosEquipe($contexto->user);

        if ($codigos === []) {
            return [
                'pessoas' => [],
                'mensagem' => 'Este perfil não consulta a equipe. O código da própria pessoa já está no contexto da conversa.',
            ];
        }

        $pessoas = User::query()
            ->select(['id', 'name', 'display_name', 'is_active'])
            ->with('vendedorPerfil:id,user_id,cod_vendedor')
            ->when($codigos !== null, fn ($q) => $q->whereHas(
                'vendedorPerfil',
                fn ($p) => $p->whereIn('cod_vendedor', $codigos),
            ))
            ->where(function ($q) use ($palavras) {
                foreach ($palavras as $palavra) {
                    $como = '%'.str_replace(['%', '_'], '', $palavra).'%';
                    $q->where(fn ($w) => $w->where('display_name', 'like', $como)->orWhere('name', 'like', $como));
                }
            })
            ->orderBy('display_name')
            ->limit(self::LIMITE)
            ->get()
            ->map(fn (User $u) => [
                'nome' => $u->display_name ?: $u->name,
                'perfil' => self::PERFIS[$u->getRoleNames()->first()] ?? $u->getRoleNames()->first(),
                'codigo' => $u->vendedorPerfil?->cod_vendedor,
                'ativo' => (bool) $u->is_active,
            ])
            ->all();

        return [
            'pessoas' => $pessoas,
            'mensagem' => $pessoas === []
                ? 'Ninguém com esse nome na equipe que você enxerga.'
                : null,
        ];
    }
}
