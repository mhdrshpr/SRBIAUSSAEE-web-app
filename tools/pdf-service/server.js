// SSAEE PDF service — remote headless-Chromium renderer (HTML in → PDF out).
// Deploy anywhere Node + Chromium run (VPS, Render, Fly.io, Railway, Docker).
//
//   POST /render   body: raw HTML (Content-Type: text/html)
//                  header: Authorization: Bearer <PDF_SERVICE_TOKEN>
//                  → 200 application/pdf
//   GET  /healthz  → "ok"
//
// The certificate HTML is fully self-contained (template + fonts + signature
// are base64 data URIs), so this service needs no filesystem assets at all.
'use strict';

const http = require('http');
const puppeteer = require('puppeteer');

const PORT = parseInt(process.env.PORT || '8090', 10);
const TOKEN = process.env.PDF_SERVICE_TOKEN || '';
const MAX_BODY = 24 * 1024 * 1024; // 24 MB (base64-inlined template/fonts)
const RENDER_TIMEOUT_MS = 40000;

let browserPromise = null;
function getBrowser() {
  if (!browserPromise) {
    browserPromise = puppeteer.launch({
      headless: 'new',
      pipe: true,
      args: [
        '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
        '--disable-gpu', '--hide-scrollbars', '--allow-file-access-from-files',
        '--font-render-hinting=none',
      ],
    });
  }
  return browserPromise;
}

async function renderPdf(html) {
  const browser = await getBrowser();
  const page = await browser.newPage();
  try {
    await page.setContent(html, { waitUntil: 'networkidle0', timeout: RENDER_TIMEOUT_MS });
    const pdf = await page.pdf({ printBackground: true, timeout: RENDER_TIMEOUT_MS });
    return Buffer.from(pdf);
  } finally {
    await page.close().catch(() => {});
  }
}

const server = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url.startsWith('/healthz')) {
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end('ok');
    return;
  }
  if (req.method !== 'POST' || !req.url.startsWith('/render')) {
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('not found');
    return;
  }
  if (TOKEN && req.headers.authorization !== 'Bearer ' + TOKEN) {
    res.writeHead(401, { 'Content-Type': 'text/plain' });
    res.end('unauthorized');
    return;
  }

  const chunks = [];
  let size = 0;
  req.on('data', c => {
    size += c.length;
    if (size > MAX_BODY) {
      res.writeHead(413, { 'Content-Type': 'text/plain' });
      res.end('payload too large');
      req.destroy();
      return;
    }
    chunks.push(c);
  });
  req.on('end', async () => {
    try {
      const html = Buffer.concat(chunks).toString('utf8');
      const pdf = await renderPdf(html);
      res.writeHead(200, {
        'Content-Type': 'application/pdf',
        'Content-Length': pdf.length,
        'Cache-Control': 'no-store',
      });
      res.end(pdf);
    } catch (err) {
      console.error('[pdf-service]', err.message);
      res.writeHead(500, { 'Content-Type': 'text/plain' });
      res.end('render failed: ' + err.message);
    }
  });
});

server.listen(PORT, () => console.log('[pdf-service] listening on :' + PORT));
