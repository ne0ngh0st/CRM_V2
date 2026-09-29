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
     *
     * ⚠️ E "Outros" É coluna, mas NÃO é etapa de negociação — ver ETAPAS_ESTEIRA logo
     * abaixo. Confundir as duas listas é o defeito que esta separação existe para
     * impedir.
     */
    public const ETAPA_NOVO = 'novo';

    public const ETAPA_EM_CONTATO = 'em_contato';

    public const ETAPA_ORCAMENTO = 'orcamento';

    public const ETAPA_NEGOCIACAO = 'negociacao';

    /**
     * Fora do funil comercial: o contato existe, mas não é venda — SAC, licitação,
     * currículo, fornecedor, alguém que errou o formulário.
     *
     * ⚠️ Isto é a Regra de ouro nº 2 virando coluna. O formulário do site é um "Fale
     * Conosco" geral que também roteia SAC, Licitação e Ouvidoria, e cada um deles tem
     * sistema próprio. Filtrar isso na ENTRADA foi tentado e está desligado de propósito
     * (`marketing_wp_formularios.assuntos_nao_comerciais` vazia): quem preenche classifica
     * de qualquer jeito, e o primeiro envio real do site chegou como "Outros" — filtrar
     * por ali descartaria orçamento de verdade, em silêncio. Aqui é o contrário: nada é
     * descartado, quem separa é o vendedor que leu o pedido, e o lead continua existindo.
     */
    public const ETAPA_OUTROS = 'outros';

    public const ETAPA_GANHO = 'ganho';

    public const ETAPA_PERDIDO = 'perdido';

    /**
     * A ESTEIRA: a negociação, em ordem. É ela — e não ETAPAS_ABERTAS — que responde
     * "qual é a próxima?" e "isto é avanço ou retrocesso?".
     *
     * ⚠️ NÃO usar ETAPAS_ABERTAS para ordem. Elas foram a mesma lista até "Outros"
     * existir, e continuar derivando a ordem dali faria o botão "→" de um lead em
     * Negociação apontar para "Outros" — ou seja, o atalho de "já tratei esse, joga pro
     * próximo" passaria a jogar o negócio para fora do funil. Nada quebraria em vermelho.
     */
    public const ETAPAS_ESTEIRA = [
        self::ETAPA_NOVO,
        self::ETAPA_EM_CONTATO,
        self::ETAPA_ORCAMENTO,
        self::ETAPA_NEGOCIACAO,
    ];

    /**
     * As colunas do quadro: a esteira mais o desvio.
     *
     * ⚠️ "Aberta" aqui significa "o card está no quadro", não "está em jogo". Quem conta
     * negócio em jogo usa ETAPAS_ESTEIRA — somar "Outros" ali infla o KPI com o que
     * nem é nosso.
     */
    public const ETAPAS_ABERTAS = [
        ...self::ETAPAS_ESTEIRA,
        self::ETAPA_OUTROS,
    ];

    public const ETAPAS_FECHADAS = [self::ETAPA_GANHO, self::ETAPA_PERDIDO];

    /** @var list<string> */
    public const ETAPAS = [
        ...self::ETAPAS_ABERTAS,
        ...self::ETAPAS_FECHADAS,
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
     * Posição da etapa NA ESTEIRA. É o que permite dizer "só avança, nunca volta" sem
     * espalhar comparações de string pelo código.
     *
     * ⚠️ Quem não está na esteira (ganho, perdido, outros) cai no fim da lista. Para os
     * desfechos isso sempre foi acidente aritmético aproveitado de propósito; para
     * "Outros" é o que mantém o card parado onde o vendedor o pôs.
     */
    public static function posicaoDaEtapa(?string $etapa): int
    {
        $posicao = array_search($etapa, self::ETAPAS_ESTEIRA, true);

        return $posicao === false ? count(self::ETAPAS_ESTEIRA) : $posicao;
    }

    public function etapaFechada(): bool
    {
        return in_array($this->etapa, self::ETAPAS_FECHADAS, true);
    }

    /** O lead está no quadro, mas fora da negociação — ver ETAPA_OUTROS. */
    public function foraDoFunilComercial(): bool
    {
        return $this->etapa === self::ETAPA_OUTROS;
    }

    /**
     * A etapa seguinte, para o botão "→" do card. `null` quando não há para onde avançar
     * (última etapa da esteira, desfecho, ou fora do funil) — o botão fica desabilitado.
     *
     * ⚠️ Deriva da ESTEIRA, nunca de ETAPAS_ABERTAS: "Outros" é a última coluna do
     * quadro, então derivar dali faria o "→" de Negociação empurrar o negócio para fora
     * do funil em vez de desabilitar.
     */
    public function proximaEtapa(): ?string
    {
        if ($this->etapaFechada() || $this->foraDoFunilComercial()) {
            return null;
        }

        return self::ETAPAS_ESTEIRA[self::posicaoDaEtapa($this->etapa) + 1] ?? null;
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
     * ⚠️ E NUNCA TIRA UM CARD DE "OUTROS". Quem pôs o lead lá leu o pedido e concluiu que
     * não é venda (SAC, licitação, currículo). Se registrar um contato o trouxesse de
     * volta para "Em contato", a triagem do vendedor seria desfeita pelo próprio ato de
     * responder ao cliente — e o desvio existiria só no papel. Voltar ao funil é decisão
     * manual, pelo botão do card.
     *
     * ⚠️ AS TRÊS GUARDAS ABAIXO SE SOBREPÕEM, e isso é intencional — não "simplificar"
     * removendo uma. `posicaoDaEtapa` devolve o fim da lista para tudo que não está na
     * ESTEIRA (ganho, perdido e outros), de modo que a comparação de posição JÁ barra os
     * três. Mas isso é acidente aritmético, não intenção declarada: quem ler só a
     * comparação não entende que desfecho é intocável nem que "Outros" é triagem humana.
     *
     * Medido por mutação em 2026-09-17, e o resultado é mais interessante que "é
     * redundante": remover `etapaFechada()` OU `foraDoFunilComercial()` não quebra teste
     * nenhum — mas trocar o `count()` de `posicaoDaEtapa` por `-1` (o retorno "natural"
     * do array_search, que alguém simplificando escreveria) faz DOIS testes falharem se
     * as guardas tiverem saído, e nenhum se elas estiverem aqui. Ou seja: elas não
     * protegem o comportamento de hoje, protegem-no de uma mudança lá em cima.
     */
    public function avancarAutomaticamentePara(string $etapa): bool
    {
        if ($this->etapaFechada() || $this->foraDoFunilComercial()) {
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
