<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Outros": a coluna do quadro para o que chegou como lead mas não é venda — SAC,
 * licitação, currículo, fornecedor.
 *
 * É a Regra de ouro nº 2 virando coluna. O formulário do site é um "Fale Conosco" geral
 * que também roteia SAC, Licitação e Ouvidoria, e cada um tem sistema próprio. Filtrar
 * isso na ENTRADA está desligado de propósito desde 2026-09-01 (o primeiro envio real
 * chegou classificado como "Outros" pelo próprio remetente): filtrar por assunto
 * descartaria orçamento de verdade, sem deixar rastro. Aqui nada é descartado — quem
 * separa é o vendedor que leu o pedido, e o lead continua existindo.
 *
 * ⚠️ O enum abaixo é LITERAL, e não `Lead::ETAPAS`. Migration é retrato de um momento: a
 * `2026_09_03_100000` montou o enum a partir da constante e, por isso, banco novo já
 * nasce com "outros" enquanto dev e produção ainda não têm — a lista mudou embaixo de uma
 * migration já aplicada. Escrevendo literal, a próxima etapa que entrar no funil não
 * reescreve o passado.
 *
 * ⚠️ Idempotente por natureza (MODIFY reaplica a mesma definição), o que importa porque
 * bancos novos chegam aqui já com a coluna correta e os antigos não.
 */
return new class extends Migration
{
    private const ENUM_COM_OUTROS = "ENUM('novo','em_contato','orcamento','negociacao','outros','ganho','perdido')";

    private const ENUM_SEM_OUTROS = "ENUM('novo','em_contato','orcamento','negociacao','ganho','perdido')";

    public function up(): void
    {
        DB::statement('ALTER TABLE leads MODIFY etapa '.self::ENUM_COM_OUTROS." NOT NULL DEFAULT 'novo'");
    }

    /**
     * ⚠️ Voltar atrás com leads em "Outros" os transformaria em string vazia no MySQL
     * (valor fora do enum), e eles sumiriam do quadro sem erro nenhum. Então o down
     * devolve cada um para "novo" — perde-se a triagem, mas o lead continua alcançável.
     */
    public function down(): void
    {
        DB::table('leads')->where('etapa', 'outros')->update(['etapa' => 'novo']);

        DB::statement('ALTER TABLE leads MODIFY etapa '.self::ENUM_SEM_OUTROS." NOT NULL DEFAULT 'novo'");
    }
};
