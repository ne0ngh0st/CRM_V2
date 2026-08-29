// Simula N sessoes autenticadas concorrentes navegando pelas paginas core do
// PALMA CRM v2, contra o stack nginx+PHP-FPM (docker-compose.loadtest.yml).
// Uso: node loadtest.mjs [usuarios] [duracaoSegundos]

const BASE_URL = process.env.LOADTEST_BASE_URL ?? 'http://localhost:8090';
const EMAIL = process.env.LOADTEST_EMAIL ?? 'antonio.barbosa@autopel.com';
// ⚠️ Nunca chumbar senha de producao aqui: este arquivo e versionado. O default e a
// senha do ambiente local; contra producao, passe LOADTEST_PASSWORD no ambiente.
const PASSWORD = process.env.LOADTEST_PASSWORD ?? 'homolog123';
const VIRTUAL_USERS = Number(process.argv[2] ?? 40);
const DURATION_MS = Number(process.argv[3] ?? 30) * 1000;

const ROUTES = [
    '/dashboard',
    '/carteira',
    '/orcamentos',
    '/pedidos-abertos',
    '/pedidos-emitidos',
    '/metas',
    '/leads',
    '/tabela-precos',
    '/equipe',
    '/cadastros',
];

function parseCookies(setCookieArray, jar) {
    for (const line of setCookieArray ?? []) {
        const [pair] = line.split(';');
        const idx = pair.indexOf('=');
        if (idx === -1) continue;
        const name = pair.slice(0, idx).trim();
        const value = pair.slice(idx + 1).trim();
        jar.set(name, value);
    }
}

function cookieHeader(jar) {
    return [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
}

/*
 * ⚠️ Contra PRODUCAO, use LOADTEST_SESSAO_UNICA=1.
 *
 * O login tem rate limit por e-mail+IP (5 tentativas). Quarenta usuarios virtuais
 * autenticando com a mesma conta estouram o limite na hora e o teste mede 40 respostas
 * 422, nao a aplicacao — foi o que aconteceu na primeira execucao contra producao, em
 * 2026-08-29. Nao e defeito do sistema: e o controle de seguranca funcionando.
 *
 * Com sessao unica, autenticamos UMA vez e os 40 workers reusam o mesmo cookie. Mede
 * concorrencia de requisicao, que e o objetivo; o que deixa de exercitar e contencao de
 * sessoes distintas no Redis, que aqui e barato (um GET por request).
 */
async function loginVirtualUser(id) {
    const jar = new Map();

    const loginPage = await fetch(`${BASE_URL}/login`, { redirect: 'manual' });
    parseCookies(loginPage.headers.getSetCookie?.() ?? [], jar);

    const xsrfRaw = jar.get('XSRF-TOKEN');
    const xsrfToken = xsrfRaw ? decodeURIComponent(xsrfRaw) : '';

    const loginRes = await fetch(`${BASE_URL}/login`, {
        method: 'POST',
        redirect: 'manual',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json, text/html',
            'X-XSRF-TOKEN': xsrfToken,
            Cookie: cookieHeader(jar),
        },
        body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
    });
    parseCookies(loginRes.headers.getSetCookie?.() ?? [], jar);

    const ok = loginRes.status === 302 || loginRes.status === 200;
    return { id, jar, ok, status: loginRes.status };
}

async function hitRoute(jar, path) {
    const start = performance.now();
    try {
        const res = await fetch(`${BASE_URL}${path}`, {
            headers: { Cookie: cookieHeader(jar) },
            redirect: 'manual',
        });
        // Drena o corpo pra contar o tempo de transferencia real, nao só o header.
        await res.arrayBuffer();
        const ms = performance.now() - start;
        return { path, ms, status: res.status, ok: res.status < 400 };
    } catch (err) {
        return { path, ms: performance.now() - start, status: 0, ok: false, error: String(err) };
    }
}

// Sessao compartilhada quando LOADTEST_SESSAO_UNICA=1 (obrigatorio contra producao).
const SESSAO_UNICA = process.env.LOADTEST_SESSAO_UNICA === '1';
// ⚠️ Guarda a PROMESSA, nao o resultado. Os 40 workers chamam isto ao mesmo tempo; se o
// slot comecasse nulo e so fosse preenchido depois do await, todos veriam nulo e
// disparariam 40 logins simultaneos — de volta ao rate limit que esta funcao evita.
let sessaoCompartilhada = null;

function obterSessao(id) {
    if (!SESSAO_UNICA) return loginVirtualUser(id);
    sessaoCompartilhada ??= loginVirtualUser(0);
    return sessaoCompartilhada;
}

async function virtualUserLoop(id, stopAt, results) {
    const { jar, ok, status } = await obterSessao(id);
    if (!ok) {
        results.push({ path: '/login', ms: 0, status, ok: false, error: 'login falhou' });
        return;
    }

    while (Date.now() < stopAt) {
        const route = ROUTES[Math.floor(Math.random() * ROUTES.length)];
        results.push(await hitRoute(jar, route));
        // Think-time pra imitar um usuario de verdade navegando, nao um martelo.
        await new Promise((r) => setTimeout(r, 300 + Math.random() * 700));
    }
}

function percentile(sorted, p) {
    if (sorted.length === 0) return 0;
    const idx = Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length));
    return sorted[idx];
}

async function main() {
    console.log(`Alvo: ${BASE_URL} | usuarios virtuais: ${VIRTUAL_USERS} | duracao: ${DURATION_MS / 1000}s`);
    const stopAt = Date.now() + DURATION_MS;
    const results = [];

    const started = performance.now();
    await Promise.all(
        Array.from({ length: VIRTUAL_USERS }, (_, i) => virtualUserLoop(i + 1, stopAt, results)),
    );
    const wallMs = performance.now() - started;

    const byRoute = new Map();
    let errors = 0;
    for (const r of results) {
        if (!r.ok) errors++;
        if (!byRoute.has(r.path)) byRoute.set(r.path, []);
        byRoute.get(r.path).push(r.ms);
    }

    console.log('\n=== Resultado por rota ===');
    for (const [path, times] of [...byRoute.entries()].sort()) {
        const sorted = [...times].sort((a, b) => a - b);
        const avg = times.reduce((a, b) => a + b, 0) / times.length;
        console.log(
            `${path.padEnd(20)} reqs=${String(times.length).padEnd(5)} ` +
            `avg=${avg.toFixed(0)}ms p50=${percentile(sorted, 50).toFixed(0)}ms ` +
            `p95=${percentile(sorted, 95).toFixed(0)}ms p99=${percentile(sorted, 99).toFixed(0)}ms ` +
            `max=${sorted[sorted.length - 1].toFixed(0)}ms`,
        );
    }

    console.log('\n=== Resumo geral ===');
    console.log(`Total de requisicoes: ${results.length}`);
    console.log(`Erros (status >=400 ou falha de rede): ${errors}`);
    console.log(`Tempo de parede do teste: ${(wallMs / 1000).toFixed(1)}s`);
    console.log(`Throughput medio: ${(results.length / (wallMs / 1000)).toFixed(1)} req/s`);
}

main().catch((err) => {
    console.error('Erro fatal no load test:', err);
    process.exit(1);
});
