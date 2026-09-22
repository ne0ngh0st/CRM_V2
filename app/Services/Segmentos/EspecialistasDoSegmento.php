<?php

namespace App\Services\Segmentos;

use App\Models\Segmento;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * O especialista de cada segmento: quem a diretoria aponta como responsável por ele
 * (a planilha dizia "DROGARIAS - Inaya").
 *
 * 🥇 UM lugar só (Regra de ouro nº 8). O especialista aparece em três telas — o quadro
 * de Segmentos da Equipe (onde é marcado, pela estrela), o Resumo da Visão Diretor e o
 * card "Segmentos Atendidos" do Painel — e as três perguntam aqui. Até 2026-09-22 o
 * formato {id, nome} estava escrito duas vezes dentro da própria Visão Diretor, e a
 * marcação era um select escondido no cabeçalho daquela página.
 *
 * ⚠️ Especialista INATIVO não aparece: quem saiu da empresa não é responsável por nada, e
 * mostrá-lo seria o tipo de dado velho que ninguém percebe. A coluna continua gravada —
 * reativar a pessoa devolve a marcação —, mas a tela diz "sem especialista".
 *
 * ⚠️ Nunca entra num bloco CACHEADO do Painel: marcar a estrela tem que aparecer no
 * request seguinte, e o bloco de segmentos vive 30 min no Redis. Por isso o Painel manda
 * este mapa numa prop à parte. Custa uma consulta sobre ~25 segmentos.
 */
class EspecialistasDoSegmento
{
    /**
     * @return array<string, array{id: int, nome: string, fotoUrl: ?string}> por código TOTVS
     */
    public function porCodigo(): array
    {
        return Segmento::query()
            ->whereNotNull('especialista_user_id')
            ->with('especialista:id,name,display_name,foto_perfil,is_active')
            ->get(['id', 'codigo', 'especialista_user_id'])
            ->filter(fn (Segmento $s) => $s->especialista?->is_active)
            ->mapWithKeys(fn (Segmento $s) => [(string) $s->codigo => self::formatar($s->especialista)])
            ->all();
    }

    /** @return array{id: int, nome: string, fotoUrl: ?string} */
    public static function formatar(User $user): array
    {
        return [
            'id' => $user->id,
            'nome' => $user->display_name ?: $user->name,
            'fotoUrl' => $user->foto_url,
        ];
    }

    /**
     * Marca (ou desmarca, com `null`) o especialista do segmento.
     *
     * ⚠️ Um por segmento: a estrela em outra pessoa TIRA a da anterior, sem pedir
     * confirmação — é o comportamento de "rádio" que a estrela promete na tela.
     */
    public function definir(Segmento $segmento, ?User $user): void
    {
        if ($user && ! $user->is_active) {
            throw ValidationException::withMessages([
                'especialista_user_id' => 'Usuário inativo não pode ser especialista de segmento.',
            ]);
        }

        $segmento->forceFill(['especialista_user_id' => $user?->id])->save();
    }
}
