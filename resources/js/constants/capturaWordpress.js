/**
 * Rótulos e ordem de exibição da ficha de captura do site.
 *
 * ⚠️ Aqui mora SÓ a apresentação. O dicionário que decide qual chave crua do WordPress
 * vira qual campo comercial é `app/Services/Marketing/WpLeadPayloadParser.php`, e é ele
 * também que a promoção do lead usa. Duplicar aquele mapa aqui faria a ficha mostrar um
 * valor e o lead guardar outro — Regra de ouro nº 8.
 *
 * A ordem abaixo é a de leitura de quem vai ligar para o cliente: quem é, como falar com
 * ele, onde está, e só então o que ele quer.
 */
export const CAMPOS_CAPTURA = [
    { chave: 'nome', rotulo: 'Nome' },
    { chave: 'empresa', rotulo: 'Empresa' },
    { chave: 'cnpj', rotulo: 'CNPJ' },
    { chave: 'telefone', rotulo: 'Telefone' },
    { chave: 'email', rotulo: 'E-mail' },
    { chave: 'cidade', rotulo: 'Cidade' },
    { chave: 'estado', rotulo: 'Estado' },
    { chave: 'endereco', rotulo: 'Endereço' },
    { chave: 'segmento', rotulo: 'Segmento' },
    { chave: 'assunto', rotulo: 'Assunto' },
    { chave: 'itens', rotulo: 'Produtos de interesse' },
    { chave: 'origem_contato', rotulo: 'Como conheceu a Autopel' },
];

/**
 * A mensagem sai da grade e vira bloco próprio: é texto livre do cliente, costuma ter
 * várias linhas, e é a parte que o vendedor realmente lê antes de ligar.
 */
export const CAMPO_MENSAGEM = { chave: 'mensagem', rotulo: 'Mensagem' };

export const ROTULOS_FONTE_CAPTURA = {
    wordpress_webhook: 'Formulário do site',
    historico_csv: 'Importação de planilha',
    teste_interno: 'Teste interno',
};
