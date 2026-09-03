/**
 * Matemática de IPI e totais do orçamento — espelho fiel de
 * `app/Services/Orcamento/OrcamentoCalculoService.php`.
 *
 * ⚠️ POR QUE ESTE ARQUIVO EXISTE (Regra de ouro nº 8):
 * a mesma matemática vivia copiada em `Pages/Orcamentos/Form.vue` e em
 * `Components/Orcamentos/ItensTabela.vue`, com arredondamentos e ordens de operação
 * DIFERENTES entre si e diferentes do PHP — o back fazia `total / 1,0325` e o front
 * fazia `qtd × (unit / 1,0325)`, e o front não arredondava em lugar nenhum. Resultado:
 * divergência de centavos entre o que a tela mostrava e o que saía no PDF, por construção.
 *
 * ⚠️ ESTE ARQUIVO E O SERVIÇO PHP SÃO UM PAR. Mexeu num, mexe no outro — e há teste dos
 * dois lados rodando os MESMOS fixtures e exigindo o mesmo número
 * (`tests/Unit/Orcamento/OrcamentoCalculoServiceTest.php`).
 *
 * A ordem de operação abaixo não é escolha estética: ela reproduz o que o servidor
 * PERSISTE. `OrcamentoController::salvarItens()` grava `valor_total = round(qtd × unit, 2)`,
 * e só então `calcularItem()` divide esse total por 1,0325. Calcular o unitário sem IPI
 * primeiro e multiplicar pela quantidade depois dá outro número.
 */

export const IPI_ALIQUOTA = 0.0325;

/**
 * Equivalente ao `round()` do PHP para os valores deste domínio (sempre >= 0).
 * ⚠️ Em negativos, PHP arredonda "half away from zero" e `Math.round` arredonda para
 * +infinito. Não há valor negativo aqui, mas não reusar isto num contexto que tenha.
 */
function arredondar(valor, casas) {
    const fator = 10 ** casas;

    return Math.round((valor + Number.EPSILON) * fator) / fator;
}

export function baseSemIpi(valorComIpi) {
    return arredondar(valorComIpi / (1 + IPI_ALIQUOTA), 4);
}

/** Etiqueta NUNCA tem IPI — regra fiscal real, forçada também no servidor. */
export function participaIpi(item, tipoProdutoServico) {
    return tipoProdutoServico === 'produto' && item.tipo_item !== 'etiqueta' && !!item.calcula_ipi;
}

export function valorUnitarioSemIpi(item, tipoProdutoServico) {
    const valor = parseFloat(item.valor_unitario) || 0;

    return participaIpi(item, tipoProdutoServico) ? baseSemIpi(valor) : valor;
}

/**
 * Espelha `OrcamentoCalculoService::calcularItem()`.
 *
 * @returns {{valorUnitarioComIpi:number, valorUnitarioSemIpi:number, valorTotalComIpi:number, valorTotalSemIpi:number, participaIpi:boolean}}
 */
export function calcularItem(item, tipoProdutoServico) {
    const qtd = parseFloat(item.quantidade) || 0;
    const valorUnitarioComIpi = parseFloat(item.valor_unitario) || 0;
    const comIpi = participaIpi(item, tipoProdutoServico);

    // Igual ao servidor: o total é arredondado em 2 casas ANTES de tirar o IPI.
    const valorTotalComIpi = arredondar(qtd * valorUnitarioComIpi, 2);

    return {
        valorUnitarioComIpi,
        valorUnitarioSemIpi: comIpi ? baseSemIpi(valorUnitarioComIpi) : valorUnitarioComIpi,
        valorTotalComIpi,
        valorTotalSemIpi: comIpi ? baseSemIpi(valorTotalComIpi) : valorTotalComIpi,
        participaIpi: comIpi,
    };
}

/**
 * Espelha `OrcamentoCalculoService::resumo()`. As três chaves SEMPRE fecham:
 * `subtotalSemIpi + valorIpi === totalGeral`.
 *
 * Os totais falam de IMPOSTO, nunca de categoria de produto — ver o docblock do serviço
 * PHP para o caso real (orçamento 2110) que motivou a mudança.
 *
 * @returns {{subtotalSemIpi:number, valorIpi:number, totalGeral:number}}
 */
export function resumo(itens, tipoProdutoServico) {
    let subtotalSemIpi = 0;
    let totalGeral = 0;

    for (const item of itens) {
        const calculado = calcularItem(item, tipoProdutoServico);
        subtotalSemIpi += calculado.valorTotalSemIpi;
        totalGeral += calculado.valorTotalComIpi;
    }

    // Arredonda antes de subtrair: é o que garante o fechamento exato em centavos.
    subtotalSemIpi = arredondar(subtotalSemIpi, 2);
    totalGeral = arredondar(totalGeral, 2);

    return {
        subtotalSemIpi,
        valorIpi: arredondar(totalGeral - subtotalSemIpi, 2),
        totalGeral,
    };
}
