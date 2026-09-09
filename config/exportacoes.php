<?php

/**
 * Central de downloads: prazo de validade e o corte entre síncrono e fila.
 *
 * ⚠️ Estes dois números existiam ANTES, espalhados: o "7 dias" estava copiado no
 * GerarExportacaoCarteiraJob (`addDays(7)`), no texto da notificação e no aviso do
 * ExportarExcelButton.vue; o limite de linhas era uma constante declarada no trait
 * ExportaPlanilha e **nunca usada por ninguém**. Regra de ouro nº 8: agora moram aqui.
 *
 * Lidos por `config()`, nunca `env()` — com o config cacheado em produção, `env()`
 * devolveria null e o prazo viraria "nunca expira" justamente onde o disco é cobrado.
 */
return [

    /*
     * Por quanto tempo a planilha fica baixável na central antes de o
     * ExpurgarExportacoesJob apagar o arquivo (o registro fica, sem o caminho: o arquivo
     * é descartável, a trilha de auditoria não).
     */
    'dias_validade' => 7,

    /*
     * Acima de quantas linhas a planilha deixa de ser gerada dentro da requisição.
     *
     * ⚠️ O NÚMERO VEM DE MEDIÇÃO, NÃO DE GOSTO (Regra de ouro nº 6), e a primeira versão
     * dele estava errada. Medido em dev, sob Docker/WSL2, com volume real:
     *
     *     Orçamentos       1.864 linhas → 1.422 ms   (0,76 ms/linha)
     *     Pedidos abertos  3.478 linhas → 2.602 ms   (0,75 ms/linha)
     *     Leads           17.173 linhas → 14.075 ms  (0,82 ms/linha)
     *     Tabela de preços 26.989 linhas → 18.720 ms (0,69 ms/linha)
     *     Carteira        92.209 linhas → ~95 s
     *
     * O custo é surpreendentemente linear (~0,75 ms/linha) porque é dominado pelo
     * PhpSpreadsheet, que mantém toda célula como objeto em memória até escrever o
     * arquivo — `WithChunkReading` reduz idas ao banco, não o trabalho por célula.
     *
     * A Regra de ouro nº 9 corta em 2 s o que pode ser síncrono. A 0,75 ms/linha, 2 s são
     * ~2.650 linhas — daí 2.500, que dá ~1,9 s no pior caso EM DEV (produção é mais
     * rápida, então a margem é real, não otimista).
     *
     * ⚠️ 5.000 foi o primeiro palpite e teria deixado Pedidos em aberto travando a aba por
     * 2,6 s, dentro do limite e fora do orçamento — o tipo de erro que só aparece medindo
     * a geração, não estimando por "esta tela é pequena".
     *
     * Quem passa daqui vai para a fila e avisa pelo sino. Não é preferência por filas: é
     * que uma aba travada por 19 s é indistinguível, para quem usa, de um sistema que
     * caiu — o trauma que originou este projeto.
     */
    'limite_linhas_sincrono' => 2500,

];
