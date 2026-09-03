<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    public const ORIGEM_SISTEMA = 'sistema';

    public const ORIGEM_MANUAL = 'manual';

    public const ORIGEM_WORDPRESS = 'wordpress';

    /** @var list<string> */
    public const ORIGENS = [
        self::ORIGEM_SISTEMA,
        self::ORIGEM_MANUAL,
        self::ORIGEM_WORDPRESS,
    ];

    /*
     * ETAPAS DO FUNIL — a ordem desta lista É a regra de negócio.
     *
     * `status` responde "este registro serve?" (ativo/excluído). `etapa` responde "onde
     * está a negociação?". Eram a mesma coluna até 2026-09-03, e por isso "convertido"
     * convivia com "inativo" como se fossem alternativas.
     *
     * ⚠️ Ganho e Perdido NÃO são colunas do quadro: são desfechos que tiram o card de
     * cena. Coluna de ganho cresce para sempre e vira lixo visual.
     */
    public const ETAPA_NOVO = 'novo';

    public const ETAPA_EM_CONTATO = 'em_contato';

    public const ETAPA_ORCAMENTO = 'orcamento';

    public const ETAPA_NEGOCIACAO = 'negociacao';

    public const ETAPA_GANHO = 'ganho';

    public const ETAPA_PERDIDO = 'perdido';

    /** Etapas em que o lead ainda está em jogo — são exatamente as colunas do quadro. */
    public const ETAPAS_ABERTAS = [
        self::ETAPA_NOVO,
        self::ETAPA_EM_CONTATO,
        self::ETAPA_ORCAMENTO,
        self::ETAPA_NEGOCIACAO,
    ];

    public const ETAPAS_FECHADAS = [self::ETAPA_GANHO, self::ETAPA_PERDIDO];

    /** @var list<string> */
    public const ETAPAS = [
        self::ETAPA_NOVO,
        self::ETAPA_EM_CONTATO,
        self::ETAPA_ORCAMENTO,
        self::ETAPA_NEGOCIACAO,
        self::ETAPA_GANHO,
        self::ETAPA_PERDIDO,
    ];

    protected $fillable = [
        'origem',
        'user_id',
        'cod_vendedor',
        'nome',
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'email',
        'telefone',
        'endereco',
        'cidade',
        'estado',
        'segmento',
        'valor_estimado',
        'status',
        'etapa',
        'etapa_alterada_em',
        'motivo_perda',
    ];

    protected function casts(): array
    {
        return [
            'valor_estimado' => 'decimal:2',
            'etapa_alterada_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function observacoes(): HasMany
    {
        return $this->hasMany(Observacao::class);
    }

    public function agendamentos(): HasMany
    {
        return $this->hasMany(AgendamentoLigacao::class);
    }

    public function stagingWordpress(): HasOne
    {
        return $this->hasOne(MarketingWpLeadRaw::class, 'lead_id');
    }

    public static function rotuloOrigem(string $origem): string
    {
        return match ($origem) {
            self::ORIGEM_MANUAL => 'Manual',
            self::ORIGEM_WORDPRESS => 'WordPress',
            default => 'Sistema',
        };
    }

    /**
     * Posição da etapa na ordem do funil. É o que permite dizer "só avança, nunca volta"
     * sem espalhar comparações de string pelo código.
     */
    public static function posicaoDaEtapa(?string $etapa): int
    {
        $posicao = array_search($etapa, self::ETAPAS_ABERTAS, true);

        return $posicao === false ? count(self::ETAPAS_ABERTAS) : $posicao;
    }

    public function etapaFechada(): bool
    {
        return in_array($this->etapa, self::ETAPAS_FECHADAS, true);
    }

    /**
     * A etapa seguinte, para o botão "→" do card. `null` quando não há para onde avançar
     * (última coluna aberta, ou lead já com desfecho) — o botão fica desabilitado.
     */
    public function proximaEtapa(): ?string
    {
        if ($this->etapaFechada()) {
            return null;
        }

        return self::ETAPAS_ABERTAS[self::posicaoDaEtapa($this->etapa) + 1] ?? null;
    }

    /**
     * Move o lead de etapa e carimba QUANDO isso aconteceu.
     *
     * ⚠️ Este é o único ponto que escreve `etapa`/`etapa_alterada_em` — arrastar, botão e
     * auto-avanço passam todos por aqui (Regra de ouro nº 8). Se alguém gravar `etapa`
     * direto num `update()`, o "parado há X dias" para de contar e o quadro passa a
     * ordenar por um carimbo velho, sem erro nenhum aparecer.
     *
     * Reaplicar a mesma etapa NÃO recarimba a data: mover um card para a coluna onde ele
     * já está não é atividade, e recarimbar zeraria o indicador de esquecimento.
     */
    public function moverParaEtapa(string $etapa, ?string $motivoPerda = null): bool
    {
        if ($this->etapa === $etapa) {
            return false;
        }

        $this->forceFill([
            'etapa' => $etapa,
            'etapa_alterada_em' => now(),
            // O motivo só faz sentido na perda; sair de "perdido" tem que limpá-lo, senão
            // o lead carrega para sempre a explicação de uma derrota que foi revertida.
            'motivo_perda' => $etapa === self::ETAPA_PERDIDO ? $motivoPerda : null,
        ])->save();

        return true;
    }

    /**
     * Avanço automático, disparado pelo que o sistema CONSEGUE observar (contato
     * registrado, orçamento criado). Devolve true se moveu.
     *
     * ⚠️ A REGRA MAIS IMPORTANTE DO FUNIL: auto-avanço NUNCA retrocede. Registrar uma
     * ligação num lead que já está em Negociação não pode puxá-lo de volta para "Em
     * contato" — o quadro passaria a brigar com o vendedor, e ele deixaria de usar.
     * E nunca toca em lead com desfecho: ligar para um cliente ganho não o reabre.
     *
     * ⚠️ AS DUAS GUARDAS ABAIXO SE SOBREPÕEM, e isso é intencional — não "simplificar"
     * removendo uma. `posicaoDaEtapa` devolve o fim da lista para etapa fechada (ganho e
     * perdido não estão em ETAPAS_ABERTAS, então o array_search falha), de modo que a
     * comparação de posição JÁ barra lead fechado. Mas isso é acidente aritmético, não
     * intenção declarada: quem ler só a comparação não entende que desfecho é
     * intocável. Confirmado por mutação: tirar `etapaFechada()` não quebra teste nenhum
     * hoje — é justamente por isso que o comentário precisa existir.
     */
    public function avancarAutomaticamentePara(string $etapa): bool
    {
        if ($this->etapaFechada()) {
            return false;
        }

        if (self::posicaoDaEtapa($etapa) <= self::posicaoDaEtapa($this->etapa)) {
            return false;
        }

        return $this->moverParaEtapa($etapa);
    }

    public function scopeVisivel($query)
    {
        return $query->where('status', '!=', 'excluido');
    }
}
