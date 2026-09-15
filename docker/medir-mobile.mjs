/**
 * Medidor de layout mobile — mede o que a suíte de testes não vê.
 *
 * Está documentado neste projeto que defeito de layout só aparece no navegador: prop com
 * nome colidindo, card mutilado por cache velho, alvo de clique de 60×21px, KpiTile com o
 * número cortado. Nenhum deles quebra em vermelho e nenhum teste de servidor os alcança.
 * Este script transforma a conferência "no olho" em número, para as três perguntas que
 * decidem se uma tela é usável no celular:
 *
 *   1. a PÁGINA rola na horizontal? (`documentElement.scrollWidth > clientWidth`)
 *   2. algum texto com `truncate` está CORTADO? (`scrollWidth > clientWidth`, a mesma
 *      técnica usada em 2026-09-14 para achar os 27 KpiTile cortados)
 *   3. algum alvo de toque está abaixo de 44px?
 *
 * ⚠️ ZERO DEPENDÊNCIA, de propósito — mesmo critério do `docker/loadtest.mjs` ao lado.
 * Fala CDP direto com o Edge/Chrome que já está instalado na máquina, via `fetch` e o
 * `WebSocket` global do Node 18+. Instalar Playwright só para isso traria ~300 MB de
 * navegador e um `package.json` a mais para manter.
 *
 * ⚠️ NÃO faz parte do build nem da suíte. É ferramenta de conferência, rodada à mão.
 *
 * Uso:
 *   node docker/medir-mobile.mjs                      # 320,360,414 nas telas de campo
 *   node docker/medir-mobile.mjs 360                  # uma largura só
 *   node docker/medir-mobile.mjs 360 /carteira,/leads # largura + rotas
 */

import { spawn } from 'node:child_process';
import { existsSync, rmSync, mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.MEDIR_BASE_URL ?? 'http://localhost:8000';
const EMAIL = process.env.MEDIR_EMAIL ?? 'antonio.barbosa@autopel.com';
const SENHA = process.env.MEDIR_SENHA ?? 'homolog123';

const LARGURAS = (process.argv[2] ?? '320,360,414').split(',').map(Number);
const ROTAS = (process.argv[3] ?? '/dashboard,/carteira,/leads,/pedidos-abertos').split(',');

/*
 * ⚠️ Número não prova layout. A medição diz que nada está cortado nem vazando; ela não
 * diz se o cartão ficou legível, se o rótulo casou com o valor, se a ordem faz sentido.
 * É a mesma lição dos PDFs de bobina/etiqueta: "gera PDF válido" passava com 11 páginas
 * em branco. Com `IMAGENS=1` cada medição também vira um PNG em storage/app/mobile/.
 */
const SALVAR_IMAGENS = process.env.IMAGENS === '1';
const PASTA_IMAGENS = join(process.cwd(), 'storage', 'app', 'mobile');

const CANDIDATOS = [
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
];

function acharNavegador() {
    const achado = CANDIDATOS.find((p) => existsSync(p));

    if (! achado) throw new Error('Nenhum Chromium encontrado nos caminhos conhecidos.');

    return achado;
}

const espera = (ms) => new Promise((r) => setTimeout(r, ms));

/** Cliente CDP mínimo: um id incremental e um mapa de promessas pendentes. */
class Cdp {
    constructor(ws) {
        this.ws = ws;
        this.id = 0;
        this.pendentes = new Map();
        ws.addEventListener('message', (ev) => {
            const msg = JSON.parse(ev.data);
            const p = this.pendentes.get(msg.id);

            if (! p) return;

            this.pendentes.delete(msg.id);
            msg.error ? p.reject(new Error(JSON.stringify(msg.error))) : p.resolve(msg.result);
        });
    }

    enviar(method, params = {}, sessionId) {
        const id = ++this.id;

        return new Promise((resolve, reject) => {
            this.pendentes.set(id, { resolve, reject });
            this.ws.send(JSON.stringify({ id, method, params, sessionId }));
        });
    }
}

async function abrirNavegador(porta, perfil) {
    const proc = spawn(acharNavegador(), [
        '--headless=new',
        `--remote-debugging-port=${porta}`,
        `--user-data-dir=${perfil}`,
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        'about:blank',
    ], { stdio: 'ignore' });

    for (let i = 0; i < 60; i++) {
        try {
            const r = await fetch(`http://127.0.0.1:${porta}/json/version`);
            const { webSocketDebuggerUrl } = await r.json();

            if (webSocketDebuggerUrl) return { proc, url: webSocketDebuggerUrl };
        } catch {
            // navegador ainda subindo
        }

        await espera(250);
    }

    throw new Error('Navegador não abriu a porta de depuração.');
}

/**
 * ⚠️ Espera o Inertia, não só o `load`. `Page.navigate` resolve quando o HTML chegou; o
 * conteúdo real é montado pelo Vue depois. Sem esta espera as medições saem de uma página
 * ainda vazia — e vazio não tem overflow nenhum, então o script diria "tudo certo".
 *
 * ⚠️ ESPERA UM SELETOR CONCRETO e ESTOURA se ele não vier, em vez de seguir depois do
 * tempo limite. A primeira versão desistia em silêncio, e o sintoma era o script acusar
 * "sem campo de e-mail" logo depois de um `docker compose restart vite` — a página tinha
 * carregado antes de o dev server voltar, então nada montou. Espera frouxa não é
 * conservadora: ela transforma "o ambiente não estava pronto" em "a tela está quebrada".
 */
async function esperarMontagem(cdp, sessao, seletor = 'main, form') {
    for (let i = 0; i < 120; i++) {
        const { result } = await cdp.enviar('Runtime.evaluate', {
            expression: `document.readyState === "complete" && !!document.querySelector(${JSON.stringify(seletor)})`,
            returnByValue: true,
        }, sessao);

        if (result.value) {
            await espera(400);

            return;
        }

        await espera(200);
    }

    throw new Error(`A página não montou "${seletor}" em 24s. O Vite está de pé (docker compose ps)?`);
}

async function avaliar(cdp, sessao, fn) {
    const { result, exceptionDetails } = await cdp.enviar('Runtime.evaluate', {
        expression: `(${fn})()`,
        returnByValue: true,
        awaitPromise: true,
    }, sessao);

    if (exceptionDetails) throw new Error(exceptionDetails.text ?? 'erro ao avaliar');

    return result.value;
}

/*
 * Roda DENTRO da página. Devolve só números e rótulos curtos: `returnByValue` serializa o
 * retorno inteiro, e devolver nós do DOM estouraria a resposta do CDP.
 */
function medir() {
    const raiz = document.documentElement;
    const larguraViewport = raiz.clientWidth;

    const identificar = (el) => {
        const texto = (el.innerText ?? '').trim().replace(/\s+/g, ' ').slice(0, 40);
        const classe = (el.className ?? '').toString().split(/\s+/).slice(0, 3).join('.');

        return `${el.tagName.toLowerCase()}.${classe} «${texto}»`;
    };

    const cortados = [...document.querySelectorAll('.truncate, [class*="text-ellipsis"]')]
        .filter((el) => el.offsetParent !== null && el.scrollWidth > el.clientWidth + 1)
        .map((el) => ({ alvo: identificar(el), conteudo: el.scrollWidth, caixa: el.clientWidth }));

    /*
     * Quem CAUSA o overflow da página. Um pai largo arrasta os filhos, então o `filter`
     * mantém só o elemento mais fundo de cada ramo — sem isso o relatório lista a árvore
     * toda e não diz onde mexer.
     */
    const vazando = [...document.querySelectorAll('body *')]
        .filter((el) => {
            if (el.offsetParent === null) return false;

            const r = el.getBoundingClientRect();

            return r.width > 0 && Math.round(r.right) > larguraViewport + 1;
        })
        .filter((el, _i, todos) => ! todos.some((outro) => outro !== el && el.contains(outro)))
        .slice(0, 8)
        .map((el) => ({ alvo: identificar(el), direita: Math.round(el.getBoundingClientRect().right) }));

    const pequenos = [...document.querySelectorAll('.tbl-acao, nav a, nav button, [role="button"]')]
        .filter((el) => {
            if (el.offsetParent === null) return false;

            const r = el.getBoundingClientRect();

            return r.height > 0 && (r.height < 44 || r.width < 44);
        })
        .map((el) => {
            const r = el.getBoundingClientRect();

            return { alvo: identificar(el), tamanho: `${Math.round(r.width)}×${Math.round(r.height)}` };
        });

    return {
        rolaHorizontal: raiz.scrollWidth > larguraViewport + 1,
        scrollWidth: raiz.scrollWidth,
        clientWidth: larguraViewport,
        cortados,
        vazando,
        pequenos,
        cartoes: document.querySelectorAll('.tbl-cartoes > tbody > tr').length,
        titulo: (document.querySelector('h1, h2')?.innerText ?? '').trim().slice(0, 60),
    };
}

async function entrar(cdp, sessao) {
    await cdp.enviar('Page.navigate', { url: `${BASE}/login` }, sessao);
    await esperarMontagem(cdp, sessao, '#email');

    const entrou = await avaliar(cdp, sessao, `async () => {
        const set = (sel, valor) => {
            const el = document.querySelector(sel);
            if (! el) return false;
            el.value = valor;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            return true;
        };

        if (! set('#email', ${JSON.stringify(EMAIL)})) return 'sem campo de e-mail';
        if (! set('#password', ${JSON.stringify(SENHA)})) return 'sem campo de senha';

        await new Promise((r) => setTimeout(r, 150));
        document.querySelector('form').requestSubmit();
        return 'enviado';
    }`);

    if (entrou !== 'enviado') throw new Error(`Login não pôde ser preenchido: ${entrou}`);

    for (let i = 0; i < 60; i++) {
        await espera(250);
        const url = await avaliar(cdp, sessao, '() => location.pathname');

        if (url !== '/login') return;
    }

    /*
     * A recusa do login é informação, não ruído: o `legado:import-usuarios` gera senha
     * aleatória, então a senha do usuário local morre a cada reimportação. Sem repetir a
     * mensagem da tela aqui, o sintoma vira "o script não funciona".
     */
    const recusa = await avaliar(cdp, sessao, `() => [...document.querySelectorAll('p, div')]
        .map((el) => el.textContent.trim())
        .find((t) => t && t.length < 200 && /credenciais|senha|inativ|permit/i.test(t)) ?? 'sem mensagem na tela'`);

    throw new Error(`Login não concluiu. A tela diz: "${recusa}"`);
}

const problemas = [];

function relatar(rota, largura, m) {
    console.log(`\n── ${rota} @ ${largura}px ${m.titulo ? `· ${m.titulo}` : ''}`);
    console.log(`   página: scrollWidth ${m.scrollWidth} / clientWidth ${m.clientWidth}` +
        (m.cartoes ? ` · ${m.cartoes} cartões` : ''));

    if (m.rolaHorizontal) {
        problemas.push(`${rota} @${largura}: página rola ${m.scrollWidth - m.clientWidth}px na horizontal`);
        console.log(`   🔴 ROLA HORIZONTAL (+${m.scrollWidth - m.clientWidth}px)`);
        m.vazando.forEach((v) => console.log(`      vaza até ${v.direita}px → ${v.alvo}`));
    }

    if (m.cortados.length) {
        problemas.push(`${rota} @${largura}: ${m.cortados.length} texto(s) cortado(s)`);
        console.log(`   🔴 ${m.cortados.length} truncate cortado(s):`);
        m.cortados.slice(0, 10).forEach((c) => console.log(`      ${c.conteudo}px numa caixa de ${c.caixa}px → ${c.alvo}`));
    }

    if (m.pequenos.length) {
        console.log(`   🟡 ${m.pequenos.length} alvo(s) abaixo de 44px:`);
        m.pequenos.slice(0, 8).forEach((p) => console.log(`      ${p.tamanho} → ${p.alvo}`));
    }

    if (! m.rolaHorizontal && ! m.cortados.length && ! m.pequenos.length) console.log('   ✅ limpo');
}

const perfil = mkdtempSync(join(tmpdir(), 'medir-mobile-'));
const porta = 9333 + Math.floor(Math.random() * 200);
const { proc, url } = await abrirNavegador(porta, perfil);

try {
    const ws = new WebSocket(url);
    await new Promise((r, j) => { ws.addEventListener('open', r); ws.addEventListener('error', j); });

    const cdp = new Cdp(ws);
    const { targetId } = await cdp.enviar('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await cdp.enviar('Target.attachToTarget', { targetId, flatten: true });

    await cdp.enviar('Page.enable', {}, sessionId);
    await cdp.enviar('Runtime.enable', {}, sessionId);

    await cdp.enviar('Emulation.setDeviceMetricsOverride', {
        width: 390, height: 844, deviceScaleFactor: 1, mobile: true,
    }, sessionId);

    console.log(`Entrando como ${EMAIL} em ${BASE}…`);
    await entrar(cdp, sessionId);

    for (const largura of LARGURAS) {
        await cdp.enviar('Emulation.setDeviceMetricsOverride', {
            width: largura, height: 800, deviceScaleFactor: 1, mobile: true,
        }, sessionId);

        for (const rota of ROTAS) {
            await cdp.enviar('Page.navigate', { url: `${BASE}${rota}` }, sessionId);
            await esperarMontagem(cdp, sessionId);

            /*
             * `ACAO=<expressão>` roda algo na página antes de medir — expandir uma linha,
             * abrir a gaveta, abrir um modal. Estado que só existe depois de um clique não
             * é medido sozinho, e é justamente ali que o layout costuma vazar.
             */
            if (process.env.ACAO) {
                await avaliar(cdp, sessionId, `async () => { ${process.env.ACAO} }`);
                await espera(1200);
            }

            relatar(rota, largura, await avaliar(cdp, sessionId, medir.toString()));

            // `SONDA=<expressão>` imprime o que ela devolver. Serve para investigar um
            // achado específico sem virar mais uma seção fixa do relatório.
            if (process.env.SONDA) {
                console.log('   🔎 ' + JSON.stringify(await avaliar(cdp, sessionId, `() => (${process.env.SONDA})`)));
            }

            if (SALVAR_IMAGENS) {
                // `FOCO=<seletor>` rola até o elemento antes de fotografar. Sem isso, numa
                // tela de 800px de altura a foto pega só o cabeçalho e os filtros.
                if (process.env.FOCO) {
                    await avaliar(cdp, sessionId, `() => {
                        document.querySelector(${JSON.stringify(process.env.FOCO)})?.scrollIntoView({ block: 'start' });
                    }`);
                    await espera(300);
                }

                const { data } = await cdp.enviar('Page.captureScreenshot', { format: 'png' }, sessionId);
                const nome = `${rota.replace(/\W+/g, '-').replace(/^-|-$/g, '') || 'raiz'}-${largura}.png`;

                mkdirSync(PASTA_IMAGENS, { recursive: true });
                writeFileSync(join(PASTA_IMAGENS, nome), Buffer.from(data, 'base64'));
                console.log(`   🖼  storage/app/mobile/${nome}`);
            }
        }
    }

    console.log('\n' + '─'.repeat(70));

    if (problemas.length) {
        console.log(`🔴 ${problemas.length} problema(s):`);
        problemas.forEach((p) => console.log(`   ${p}`));
        process.exitCode = 1;
    } else {
        console.log('✅ Nenhum corte e nenhum overflow horizontal nas larguras medidas.');
    }
} finally {
    proc.kill();
    await espera(500);

    try {
        rmSync(perfil, { recursive: true, force: true });
    } catch {
        // perfil temporário; se o navegador ainda segura um handle, o SO limpa depois
    }
}
