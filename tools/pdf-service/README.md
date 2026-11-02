# SSAEE PDF Service (self-hosted Browserless alternative)

Headless Chromium that accepts the certificate HTML (fully self-contained —
template, YekanBakh fonts and signature arrive as data URIs) and returns a
print-engine PDF: real HarfBuzz shaping, selectable Persian text, RTL/bidi
handled by Chrome itself. The app talks to it only via the `raw` provider.

## Run (Docker)
```bash
docker run -d --name ssaee-pdf -p 8090:8090 \
  -e PDF_SERVICE_TOKEN=long-random-secret \
  -v "$PWD:/app" node:20 \
  sh -lc "cd /app && npm install && node server.js"
```
Verify:
```bash
curl http://127.0.0.1:8090/healthz                                   # ok
printf '<h1>سلام — محمدرضا احمدی</h1>' > /tmp/t.html
curl -s -X POST -H 'Authorization: Bearer long-random-secret' \
     --data-binary @/tmp/t.html http://127.0.0.1:8090/render -o /tmp/t.pdf
file /tmp/t.pdf                                                      # PDF document
```

## Wire into SSAEE (v2/.env)
```
CERT_PDF_PROVIDER=raw
CERT_PDF_ENDPOINT=http://127.0.0.1:8090/render   # or https via your proxy
CERT_PDF_TOKEN=long-random-secret
CERT_PDF_TIMEOUT=30
CERT_PDF_CONNECT_TIMEOUT=5
CERT_PDF_CACHE_ENABLED=true
CERT_PDF_RENDER_VERSION=1
CERT_PDF_TEMPLATE_VERSION=1
```
If the service is on another machine, expose it **HTTPS only** behind a
proxy/basic-auth and use a strong `PDF_SERVICE_TOKEN`.

## Using Browserless.io instead (no self-hosting)
```
CERT_PDF_PROVIDER=browserless
CERT_PDF_API_KEY=<your browserless token>
```
Same pipeline; switching later is only config.
