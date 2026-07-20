/**
 * xss-bot — Servicio de soporte para el Reto 1 (Stored XSS).
 *
 * Simula a un administrador que revisa tickets del helpdesk en un navegador real (Chromium headless).
 * Su cookie de sesión contiene FLAG_WEBAPP_XSS embebida en el JWT (ver bot_login.php y auth.php).
 * La cookie se emite sin HttpOnly (vulnerabilidad deliberada), por lo que un payload XSS puede leerla
 * con document.cookie y exfiltrarla — este es el objetivo del Reto 1.6.
 *
 * Flujo:
 *   1. Cada VISIT_INTERVAL segundos, consulta /bot/queue para obtener tickets sin visitar.
 *   2. Para cada ticket, obtiene una cookie de admin fresca vía /bot_login.php.
 *   3. Visita /ticket?id=X con esa cookie, espera PAGE_TIMEOUT ms (ejecutando cualquier JS de la página).
 *   4. Marca el ticket como visitado en /bot/mark_visited.
 *
 * No es un reto en sí: los participantes no interactúan con este servicio directamente.
 */

'use strict';

const { chromium } = require('playwright');

const WEBAPP_BASE_URL     = (process.env.WEBAPP_BASE_URL || 'http://webapp:80').replace(/\/$/, '');
const BOT_SECRET          = process.env.BOT_SECRET || '';
const VISIT_INTERVAL_MS   = parseInt(process.env.BOT_VISIT_INTERVAL_SECONDS || '30', 10) * 1000;
// Tiempo máximo que el bot permanece en cada página. Previene que un payload while(true) lo cuelgue.
const PAGE_TIMEOUT_MS     = 5000;

async function fetchJson(url, opts = {}) {
    const res = await fetch(url, {
        ...opts,
        headers: { 'X-Bot-Secret': BOT_SECRET, ...(opts.headers || {}) },
    });
    if (!res.ok) throw new Error(`HTTP ${res.status} en ${url}`);
    return res.json();
}

async function getQueue() {
    return fetchJson(`${WEBAPP_BASE_URL}/bot/queue`);
}

async function markVisited(queueId) {
    await fetchJson(`${WEBAPP_BASE_URL}/bot/mark_visited?id=${queueId}`);
}

async function getAdminCookie() {
    const res = await fetch(`${WEBAPP_BASE_URL}/bot_login.php`, {
        headers: { 'X-Bot-Secret': BOT_SECRET },
    });
    if (!res.ok) throw new Error(`bot_login.php devolvió ${res.status}`);

    // Extrae el valor de la cookie `session` del header Set-Cookie.
    const setCookie = res.headers.get('set-cookie') || '';
    const match = setCookie.match(/(?:^|,\s*)session=([^;,]+)/);
    if (!match) throw new Error('bot_login.php no devolvió cookie de sesión');
    return match[1];
}

async function visitTicket(browser, queueId, ticketId) {
    let context = null;
    try {
        const sessionValue = await getAdminCookie();

        context = await browser.newContext({
            // Sin CSP ni restricciones adicionales — la webapp no define CSP a propósito.
        });
        await context.addCookies([{
            name:     'session',
            value:    sessionValue,
            url:      WEBAPP_BASE_URL,
            httpOnly: false,   // Coincide con la cookie emitida por auth.php (vuln intencional).
            sameSite: 'Lax',
        }]);

        const page = await context.newPage();
        page.setDefaultTimeout(PAGE_TIMEOUT_MS);
        page.setDefaultNavigationTimeout(PAGE_TIMEOUT_MS);

        const ticketUrl = `${WEBAPP_BASE_URL}/ticket?id=${ticketId}`;
        console.log(`[bot] Visitando ticket #${ticketId} (queue id=${queueId})`);

        // Navega a la página del ticket. Si la página contiene XSS, se ejecuta aquí.
        await page.goto(ticketUrl).catch(() => {});
        // Espera PAGE_TIMEOUT_MS para dar tiempo a que el payload JS complete su exfiltración.
        await page.waitForTimeout(PAGE_TIMEOUT_MS).catch(() => {});

        await markVisited(queueId);
        console.log(`[bot] Ticket #${ticketId} marcado como visitado`);
    } catch (err) {
        console.error(`[bot] Error visitando ticket #${ticketId}:`, err.message);
    } finally {
        if (context) await context.close().catch(() => {});
    }
}

async function main() {
    console.log('[bot] Iniciando xss-bot...');
    console.log(`[bot] WEBAPP_BASE_URL=${WEBAPP_BASE_URL}`);
    console.log(`[bot] Intervalo de polling=${VISIT_INTERVAL_MS / 1000}s, timeout por página=${PAGE_TIMEOUT_MS / 1000}s`);

    const browser = await chromium.launch({ headless: true });

    while (true) {
        try {
            const queue = await getQueue();
            if (queue.length > 0) {
                console.log(`[bot] ${queue.length} ticket(s) en cola`);
            }
            for (const item of queue) {
                await visitTicket(browser, item.id, item.ticket_id);
                // Pausa breve entre visitas para no saturar la webapp.
                await new Promise(r => setTimeout(r, 1000));
            }
        } catch (err) {
            // La webapp puede no estar lista en el arranque: reintentar en el próximo ciclo.
            console.error('[bot] Error en el ciclo de polling:', err.message);
        }

        await new Promise(r => setTimeout(r, VISIT_INTERVAL_MS));
    }
}

main().catch(err => {
    console.error('[bot] Error fatal:', err);
    process.exit(1);
});
