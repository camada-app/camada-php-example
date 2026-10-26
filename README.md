# camada-php-example

A plain-PHP front controller wired with [`camada`](../camada-php) against a locally running
edge-analyst. This is the hand-test bench for the PHP SDK, the twin of
[`camada-python-example`](../camada-python-example). No framework on purpose: the point is that
the SDK works with `$_SERVER`, `php://input` and `header()` alone (`public/index.php` is the whole
app; Laravel users take `Camada\Laravel\Middleware` instead).

## Setup

1. Terminal A — `cd ../camada/edge-analyst && npm run dev` (analyst on :8787), then
   `npm run seed` in a second terminal. Note the printed `CAMADA_KEY`.
2. Here: `cp .env.example .env` (paste the key if it differs), `composer install`, then
   `composer serve` → http://localhost:3005. That is
   `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:3005 -t public public/index.php`: the router-script
   form routes every path through the controller, and four workers so the post-response work of
   one request never delays the next. `composer install` links the sibling checkout `../camada-php`
   as a path repository.

`CAMADA_TRUSTED_PROXY=hops:1` in `.env.example` is dev-only: it lets
`curl -H 'X-Forwarded-For: …'` exercise blocking through localhost. `php -S` itself never rewrites
the peer, so nothing stands between the header and camada's trusted-proxy rules.

## Where the state lives, and when the events ship

PHP has no long-lived process, so the SDK keeps its runtime **on disk**, shared by every worker:
the snapshot (`snapshot.bin`, seeked into per request), its state, and the event spool
(`events.ndjson`) live in `CAMADA_CACHE_DIR` — by default `<system temp dir>/camada-<hash of the
snapshot token>`; `ls "$(php -r 'echo sys_get_temp_dir();')"/camada-*` shows it. Nothing polls in
the background: a request whose cached snapshot is stale arms a refresh, and a request that finds
the spool due (≥ 500 rows, or 15 s since the last flush) ships it — both **after the response has
left**. Under `php -S` that means the body goes out with a `Content-Length`, the client stops
reading, and the worker does the deferred work before taking its next connection. Under
PHP-FPM it is `fastcgi_finish_request()`. So: events reach the analyst on the *next* request after
the flush interval, and a quiet site ships its last batch on its next hit.

Behind nginx, the `real_ip` module (`set_real_ip_from` / `real_ip_header X-Forwarded-For`) is what
rewrites `REMOTE_ADDR` before PHP sees it. With it the peer camada reads is already the client,
and the trusted-proxy config should say `none`; without it, `REMOTE_ADDR` is nginx and `hops:1`
(or the tenant config) tells camada which `X-Forwarded-For` entry to believe.

## Hand test (what to look for)

1. Browse `http://localhost:3005` — the page renders; devtools → Network shows
   `/_cam/b.js?r=<uuid>` (200, JavaScript) and ~2 s later `POST /_cam/fp` (204). The document
   response carries an `x-rid` header and sets the `_sfp` cookie.
2. Click/type before the beacon fires — the `/_cam/fp` payload's `input` counters are non-zero.
3. `curl -i http://localhost:3005/ -H 'X-Forwarded-For: 203.0.113.66'` → **403** with
   `x-block-reason: rule` and `x-block-rule: builtin:block` (the seed blocks that IP as an entry
   of the built-in block list on snapshot v5). The very first request after boot answers **200**
   instead — see below.
4. Fail three logins (`demo@example.com` / anything wrong) via `/login-form`, make one more
   request after 15 s so the spool ships, then run an analysis
   (`curl -s -X POST -H 'authorization: Bearer dev' 'http://localhost:8787/admin/run?tenant=acme&minutes=10'`) —
   events include `login_failed` rows with a hashed `uid`; your raw email appears nowhere.
5. Kill the analyst (Ctrl-C in terminal A) and reload the page + `curl localhost:3005/api/data` —
   everything still answers 200; the app's terminal shows at most one
   `[camada] suppressed error` line a minute. That is fail-open.
6. Restart this app with `CAMADA_DISABLED=1` — no `x-rid` header, no `/_cam/b.js` requests:
   the kill switch bypasses the SDK entirely.

## The first request after boot is cold

The engine is built on the first request through `Sapi::run()`, and the request path never opens
a socket: the first request reads an empty cache, falls open (a `curl` from the blocked IP right
after boot gets `200` with an `x-rid`) and arms the refresh, which runs after that response has
left. The next request enforces from the cached container — a few hundred milliseconds later
against a local analyst (the e2e measures it with a 250 ms probe; the figure is snapshot-size and
network bound), then `403`. `node scripts/e2e-sdk-php.mjs` in `../camada/edge-analyst` makes that
first request itself and asserts both answers. The cache survives restarts by design: a
redeployed app on the same host enforces from request 1.

To enforce from request 1 on a fresh host, warm the cache in a deploy step (`refresh()` is
synchronous and single in-flight, so the loop is bounded):

```php
$cam = Camada\Camada::default();
if ($cam->snap !== null) {                    // null when CAMADA_KEY is unset or CAMADA_DISABLED=1
    $deadline = microtime(true) + 5;
    while ($cam->snap->verdict(new Camada\Snapshot\MatchInput(ip: '0.0.0.0'))->reason === 'cold' && microtime(true) < $deadline) {
        $cam->snap->refresh();                // bounded: an unreachable analyst leaves it cold, and the app still fails open
        usleep(10_000);
    }
}
```

## Challenge (SDK-04)

`/challenge-me` forces the first-party proof-of-work challenge, whatever the snapshot says — the
same page camada serves automatically for a `challenge` verdict.

1. Browse `http://localhost:3005/challenge-me` — "Checking your browser" appears, the inline
   solver hunts a SHA-256 with 16 leading zero bits (tens of milliseconds), the hidden form
   posts to `/__camada/challenge`, and the browser lands on "Challenge passed". Devtools shows
   the `_cch` cookie (`HttpOnly`, `SameSite=Lax`, one hour); reload and the page renders at once.
2. `curl -i http://localhost:3005/challenge-me -H 'accept: text/html' -H 'sec-fetch-dest: document'`
   → **403** with `x-camada-challenge: 1` and the page in the body.
3. A request without an HTML `Accept` (an API client, an image, a fetch) cannot solve a challenge,
   so it passes to the app signalled: the route renders (**200**) with `x-camada-challenge: 1` and
   `cache-control: no-store`, and the app or client decides what to do.
4. The events tell the three apart: a served challenge ships `st: 403, blk: "challenge"`, a passed
   one ships `st: 200, ch: 1`, and a signalled one (step 3) ships `st: 200, chs: 1`.

The nonce and the cookie are bound to the client IP, so camada serves no challenge to a request
it cannot identify (no trusted-proxy config and no socket address). Clear `_cch` — or open a
private window — to see the check again.

## Tests

`composer test` — the routes over a real `php -S` against an engine whose analyst URL is a closed
port (cold, fail open; the challenge needs no snapshot), each test on its own cache dir, plus a
guard that `vendor/camada/camada` is the sibling checkout at its current version.
