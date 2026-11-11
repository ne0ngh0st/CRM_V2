<?php

namespace App\Services\Cadastros;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Em nome de quem uma solicitação de cadastro pode ser feita (2026-11-04).
 *
 * A assistente da supervisão abre cadastro de bobina, etiqueta, cliente e lead pelos
 * vendedores da equipe — pedido do Tony. A solicitação nasce como SE o vendedor a tivesse
 * feito: `user_id`, nome e código são dele, e é ele quem recebe a cópia do e-mail.
 *
 * Vale para quem olha a equipe do próprio código (`User::PERFIS_COM_EQUIPE`: supervisor e
 * assistente). A equipe é a mesma da tela: o titular do código (o supervisor) mais os
 * vendedores com `cod_super` apontando para ele.
 *
 * ⚠️ O id escolhido vem da requisição. Fora da equipe é erro de validação, nunca "cai para
 * quem está logado" em silêncio: a pessoa acharia que pediu pelo vendedor certo.
 */
class SolicitanteDaEquipe
{
    /** @var array<int, Collection<int, User>> */
    private array $memo = [];

    public function podeEscolher(User $user): bool
    {
        return $user->hasAnyRole(User::PERFIS_COM_EQUIPE) && (bool) $user->vendedorPerfil?->cod_vendedor;
    }

    /**
     * Quem pode ser escolhido, com quem está logado primeiro.
     *
     * @return Collection<int, User>
     */
    public function opcoes(User $user): Collection
    {
        if (! $this->podeEscolher($user)) {
            return collect([$user]);
        }

        return $this->memo[$user->id] ??= $this->calcular($user);
    }

    /** @return array<int, array{id: int, nome: string, eu: bool}> para o select da tela */
    public function paraTela(User $user): array
    {
        if (! $this->podeEscolher($user)) {
            return [];
        }

        return $this->opcoes($user)
            ->map(fn (User $u) => ['id' => $u->id, 'nome' => $u->display_name ?: $u->name, 'eu' => $u->id === $user->id])
            ->values()
            ->all();
    }

    /** @return list<int> ids cujas solicitações quem está logado enxerga e pode reenviar */
    public function idsDaEquipe(User $user): array
    {
        return $this->opcoes($user)->pluck('id')->all();
    }

    public function resolver(User $user, mixed $emNomeDe): User
    {
        if ($emNomeDe === null || $emNomeDe === '' || (int) $emNomeDe === $user->id) {
            return $user;
        }

        $alvo = $this->opcoes($user)->firstWhere('id', (int) $emNomeDe);

        if ($alvo === null) {
            throw ValidationException::withMessages([
                'em_nome_de' => 'Você só pode solicitar em nome de alguém da sua equipe.',
            ]);
        }

        return $alvo;
    }

    /** @return Collection<int, User> */
    private function calcular(User $user): Collection
    {
        $codigo = $user->vendedorPerfil->cod_vendedor;

        $equipe = User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->where(fn ($q) => $q
                // O titular do código (o supervisor que a assistente apoia).
                ->whereHas('vendedorPerfil', fn ($v) => $v->titulares()->where('cod_vendedor', $codigo))
                // Quem vende na equipe dele.
                ->orWhere(fn ($q2) => $q2
                    ->comPerfil(User::PERFIS_CARTEIRA)
                    ->whereHas('vendedorPerfil', fn ($v) => $v->where('cod_super', $codigo))))
            ->with('vendedorPerfil')
            ->get()
            ->sortBy(fn (User $u) => $u->display_name ?: $u->name)
            ->values();

        return collect([$user])->concat($equipe);
    }
}
