/**
 * Service worker do PALMA — existe para o Chrome oferecer "Adicionar à tela inicial".
 *
 * ⚠️ NÃO cacheia HTML nem resposta do Inertia. Carteira, Leads, Painel e o resto são
 * dados vivos; um cache aqui serviria número velho com cara de página certa, sem erro
 * nenhum aparecer. O handler de `fetch` ignora tudo que não for asset hashado do Vite.
 *
 * ⚠️ O Chrome EXIGE um handler de `fetch` para o critério de instalabilidade. Por isso
 * ele existe mesmo quando deixa a requisição passar adiante — sem `respondWith` o
 * browser vai à rede normalmente.
 *
 * Assets em `/build/assets/` têm hash no nome e são imutáveis (o nginx já manda
 * `Cache-Control: immutable`). Guardá-los no Cache Storage é ganho de latência em
 * visita repetida no celular, sem o risco de servir página velha.
 */
const CACHE = 'palma-estaticos-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const nomes = await caches.keys();
        await Promise.all(
            nomes.filter((nome) => nome !== CACHE).map((nome) => caches.delete(nome)),
        );
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (! url.pathname.startsWith('/build/assets/')) {
        return;
    }

    event.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const hit = await cache.match(req);

        if (hit) {
            return hit;
        }

        const res = await fetch(req);

        if (res.ok) {
            cache.put(req, res.clone());
        }

        return res;
    })());
});
