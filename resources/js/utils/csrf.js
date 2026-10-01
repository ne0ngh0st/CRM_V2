/**
 * Token CSRF para `fetch` (o Laravel aceita o valor do cookie XSRF-TOKEN, decodificado,
 * no header `X-XSRF-TOKEN`). Para quando a ação não é navegação e o `router` do Inertia
 * não serve — ver o uso no `FunilQuadro` e no `BotaoInativacao`.
 */
export function tokenCsrf() {
    const bruto = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));

    return bruto ? decodeURIComponent(bruto.split('=')[1]) : '';
}

/** POST de JSON com sessão e CSRF. Devolve a `Response` crua — quem chama decide o que é erro. */
export function postJson(url, corpo = {}) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': tokenCsrf(),
        },
        body: JSON.stringify(corpo),
    });
}
