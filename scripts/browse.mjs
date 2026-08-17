/**
 * A Playwright page pointed at the live app, from inside an agent sandbox.
 *
 * Chromium cannot talk to the internet here: every TLS handshake it opens is
 * reset before the server answers, direct and through HTTPS_PROXY alike, and
 * no combination of TLS flags changes that. Node and curl get out fine — so
 * the way in is to stop asking Chromium to speak to the outside at all.
 *
 * This runs a CONNECT proxy on loopback that terminates TLS itself with a
 * throwaway certificate and replays each request through node's HTTPS client.
 * Chromium trusts that one certificate by the SHA-256 of its public key: a
 * pin, not `ignoreHTTPSErrors`, so everything else still needs a real chain.
 */

import { execFileSync } from "node:child_process";
import fs from "node:fs";
import http from "node:http";
import https from "node:https";
import os from "node:os";
import path from "node:path";
import { chromium } from "playwright";

export const BASE_URL = "https://logralo.fgilio.com";

/** The phone the group actually uses the app on. */
const PHONE = {
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
};

/** Drop the headers that describe one hop and must not travel to the next. */
function strip(headers, ...also) {
    const hop = ["connection", "keep-alive", "transfer-encoding", "upgrade"];
    for (const header of [...hop, "alt-svc", ...also]) delete headers[header];

    return headers;
}

/** A throwaway certificate, and the pin Chromium recognises it by. */
function certificate() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), "logralo-browse-"));
    const [key, cert] = ["key.pem", "cert.pem"].map((f) => path.join(dir, f));
    const openssl = (script) => execFileSync("sh", ["-c", script]).toString();

    openssl(`openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
        -subj /CN=logralo-browse -keyout '${key}' -out '${cert}' 2>/dev/null`);
    const spki = openssl(`openssl x509 -in '${cert}' -pubkey -noout \
        | openssl pkey -pubin -outform der \
        | openssl dgst -sha256 -binary | openssl enc -base64`);

    return { key: fs.readFileSync(key), cert: fs.readFileSync(cert), spki };
}

/** Replay one intercepted request against the real origin, over `agent`. */
const forward = (agent) => (req, res) => {
    const host = (req.headers.host ?? "").split(":")[0];
    const headers = strip({ ...req.headers }, "accept-encoding");

    const upstream = https.request(
        {
            agent,
            host,
            path: req.url,
            method: req.method,
            headers,
            servername: host,
        },
        (origin) => {
            const answer = strip({ ...origin.headers }, "content-length");
            res.writeHead(origin.statusCode, answer);
            origin.pipe(res);
        },
    );

    upstream.on("error", (error) => {
        if (!res.headersSent) res.writeHead(502);
        res.end(`browse.mjs could not reach ${host}: ${error.message}`);
    });

    req.pipe(upstream);
};

/** Open the app in a phone-sized Chromium. Close it with the handle returned. */
export async function openLogralo(contextOptions = PHONE) {
    const { key, cert, spki } = certificate();
    // The proxy's own pool, so tearing it down leaves the caller's other
    // requests — and the global agent they run on — alone.
    const pool = new https.Agent({ keepAlive: true });
    const tls = https.createServer({ key, cert }, forward(pool));
    const bridge = http.createServer((_, res) => res.writeHead(501).end());

    bridge.on("connect", (_, socket) => {
        socket.write("HTTP/1.1 200 Connection Established\r\n\r\n");
        tls.emit("connection", socket);
    });
    await new Promise((ready) => bridge.listen(0, "127.0.0.1", ready));

    const browser = await chromium.launch({
        proxy: { server: `http://127.0.0.1:${bridge.address().port}` },
        args: [
            "--no-sandbox",
            `--ignore-certificate-errors-spki-list=${spki.trim()}`,
        ],
    });
    const context = await browser.newContext(contextOptions);

    return {
        browser,
        context,
        page: await context.newPage(),
        async close() {
            await browser.close();
            bridge.close();
            // Both ends hold sockets open past the last request, and either
            // one of them left open holds the whole process open with it.
            bridge.closeAllConnections();
            pool.destroy();
        },
    };
}

/** Log in the way a member does, and land on the one screen. */
export async function signIn(page, email, password) {
    await page.goto(`${BASE_URL}/entrar`, { waitUntil: "domcontentloaded" });
    await page.fill("input[type=email]", email);
    await page.fill("input[type=password]", password);
    await page.click("button[type=submit]");
    await page.waitForURL((url) => !url.pathname.startsWith("/entrar"));
    await page.waitForLoadState("domcontentloaded");
}
