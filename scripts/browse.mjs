/**
 * A Playwright page pointed at the live app, from inside an agent sandbox.
 *
 * Chromium cannot talk to the internet here: every TLS handshake it opens is
 * reset before the server answers, direct and through HTTPS_PROXY alike, and
 * no combination of TLS flags changes that. Node and curl get out fine, so
 * this runs a CONNECT proxy on loopback that terminates TLS itself with a
 * throwaway certificate and replays each request through node. Chromium
 * trusts that one certificate by the SHA-256 of its public key: a pin, not
 * `ignoreHTTPSErrors`, so everything else still needs a real chain.
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

    try {
        openssl(`openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
            -subj /CN=logralo-browse -keyout '${key}' -out '${cert}' 2>/dev/null`);
        const spki = openssl(`openssl x509 -in '${cert}' -pubkey -noout \
            | openssl pkey -pubin -outform der \
            | openssl dgst -sha256 -binary | openssl enc -base64`);

        return { key: fs.readFileSync(key), cert: fs.readFileSync(cert), spki };
    } finally {
        // A private key on disk has no business outliving the call that made it.
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

/** Replay one intercepted request against the real origin, over `agent`. */
const forward = (agent) => (req, res) => {
    const host = (req.headers.host ?? "").split(":")[0];
    const headers = strip({ ...req.headers }, "accept-encoding");
    const { method, url: path } = req;

    const upstream = https.request(
        { agent, host, servername: host, headers, method, path },
        (origin) => {
            // A reset arriving mid-body would otherwise take the proxy with it.
            origin.on("error", () => res.destroy());
            res.writeHead(
                origin.statusCode,
                strip({ ...origin.headers }, "content-length"),
            );
            origin.pipe(res);
        },
    );

    upstream.setTimeout(30_000, () => upstream.destroy(new Error("timed out")));
    upstream.on("error", (error) => {
        if (res.headersSent) return res.destroy();
        res.writeHead(502).end(
            `browse.mjs could not reach ${host}: ${error.message}`,
        );
    });

    req.pipe(upstream);
};

/** Open the app in a phone-sized Chromium. Close it with the handle returned. */
export async function openLogralo(contextOptions = PHONE) {
    const { key, cert, spki } = certificate();
    // Its own pool, so tearing this down leaves the caller's other requests
    // — and the global agent they run on — alone.
    const pool = new https.Agent({ keepAlive: true });
    const tls = https.createServer({ key, cert }, forward(pool));
    const bridge = http.createServer((_, res) => res.writeHead(501).end());
    const teardown = () => {
        // Both ends hold sockets open past the last request, and either one
        // of them left open holds the whole process open with it.
        bridge.close();
        bridge.closeAllConnections();
        pool.destroy();
    };

    bridge.on("connect", (_, socket) => {
        socket.write("HTTP/1.1 200 Connection Established\r\n\r\n");
        tls.emit("connection", socket);
    });
    await new Promise((ready) => bridge.listen(0, "127.0.0.1", ready));
    // An idle listener that cannot hold the process open, so a launch that
    // throws below exits with its error rather than hanging on this socket.
    bridge.unref();

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
            try {
                await browser.close();
            } finally {
                teardown();
            }
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
