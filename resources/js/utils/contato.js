/*
 * Normalização de telefone e montagem dos links de contato.
 *
 * `normalizarTelefone` estava copiada, idêntica e linha a linha, em CarteiraTabela.vue
 * e LeadsTabela.vue — e agora seriam três telas (Regra de ouro nº 8).
 */

/** DDI do Brasil. Toda base do TOTVS é nacional; número já com DDI não é reprefixado. */
const DDI_BR = '55';

/** Só os dígitos, sem zeros à esquerda (a base tem "011", "0800" e afins). */
function digitos(telefone) {
    return String(telefone ?? '').replace(/\D/g, '').replace(/^0+/, '');
}

/**
 * Número no formato que o discador do Windows/celular espera: DDD + número, com o
 * zero de operadora na frente quando há DDD. Mantido igual ao que a Carteira e os
 * Leads já faziam — é o "puxa o ramal" herdado do legado.
 */
export function normalizarTelefone(telefone) {
    let numero = digitos(telefone);
    if (numero.length === 10 || numero.length === 11) {
        numero = `0${numero}`;
    }

    return numero;
}

/**
 * `true` quando dá pra montar um link de WhatsApp: o wa.me exige DDI + DDD + número.
 *
 * ⚠️ Número de 8 ou 9 dígitos (sem DDD) NÃO serve — o wa.me aceitaria a URL e abriria
 * o WhatsApp num número errado ou inexistente, sem erro nenhum. São ~5,6 mil clientes
 * da base nessa situação, então o botão fica desabilitado em vez de mentir.
 */
export function temWhatsapp(telefone) {
    const n = digitos(telefone);

    return n.length === 10 || n.length === 11 || (n.length >= 12 && n.startsWith(DDI_BR));
}

/** `https://wa.me/55DDDNUMERO` — abre o app no desktop e no celular. */
export function linkWhatsapp(telefone) {
    const n = digitos(telefone);

    return `https://wa.me/${n.startsWith(DDI_BR) && n.length >= 12 ? n : DDI_BR + n}`;
}

/** `tel:` no celular, `callto:` no desktop (é o que o discador da Autopel registra). */
export function linkTelefone(telefone) {
    const numero = normalizarTelefone(telefone);
    const ehMobile = /Mobi|Android/i.test(navigator.userAgent);

    return `${ehMobile ? 'tel' : 'callto'}:${numero}`;
}

export function linkEmail(email) {
    return `mailto:${String(email ?? '').trim()}`;
}
