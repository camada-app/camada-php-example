<?php

// camada-php-example: a plain front controller wired with camada against a local edge-analyst.
// Setup: cp .env.example .env (paste the CAMADA_KEY printed by `npm run seed`), composer install,
// then `composer serve` (php -S on :3005 with 4 workers, every path routed through this file).
// No framework on purpose: the SDK works with $_SERVER + php://input + header() alone.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Camada\Camada;
use Camada\Context;
use Camada\Http\Sapi;

// minimal .env loader so the example has zero extra dependencies; the environment wins over the file
foreach (@file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m) === 1 && getenv($m[1]) === false) {
        putenv("{$m[1]}={$m[2]}");
    }
}

$cam = Camada::default();      // ← the two-line install; the engine builds itself from the environment
$ctx = Sapi::run($cam);        // null: camada answered (block, challenge, beacon) — stop here
if ($ctx === null) {
    return;
}

function page(Camada $cam, Context $ctx, string $title, string $body, int $status = 200): void
{
    http_response_code($status);
    header('content-type: text/html; charset=utf-8');
    $t = htmlspecialchars($title, ENT_QUOTES);
    echo <<<HTML
    <!doctype html>
    <html><head><meta charset="utf-8"><title>{$t}</title>{$cam->scriptTag($ctx)}</head>
    <body style="font-family: system-ui; max-width: 40rem; margin: 3rem auto">
    <nav><a href="/">home</a> · <a href="/pricing">pricing</a> · <a href="/login-form">login</a> · <a href="/challenge-me">challenge</a></nav>
    <h1>{$t}</h1>{$body}</body></html>
    HTML;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

match (true) {
    $path === '/' => page($cam, $ctx, 'camada example shop', <<<HTML

      <p>Every request here is captured by camada; the beacon below fingerprints this browser first-party.</p>
      <p><button onclick="fetch('/api/data').then(r=>r.json()).then(d=>alert(JSON.stringify(d)))">call the API</button></p>
    HTML),

    $path === '/pricing' => page($cam, $ctx, 'Pricing', '<p>Free while unreleased.</p>'),

    $path === '/login-form' => page($cam, $ctx, 'Log in', <<<HTML

      <form method="post" action="/login">
        <input name="user" placeholder="email"> <input name="pass" type="password"> <button>go</button>
      </form>
    HTML),

    $path === '/login' && $method === 'POST' => (static function () use ($cam, $ctx): void {
        // parse the urlencoded body by hand: the example reads php://input the way the SDK leaves it, untouched
        parse_str((string) file_get_contents('php://input'), $form);
        $user = is_string($form['user'] ?? null) ? $form['user'] : '';
        $ok = $user === 'demo@example.com' && ($form['pass'] ?? null) === 'demo';
        $cam->track($ctx, $ok ? 'login_succeeded' : 'login_failed', $user);   // uid is HMAC-hashed in the SDK
        page($cam, $ctx, $ok ? 'Welcome' : 'Nope', '<p>login ' . ($ok ? 'succeeded' : 'failed') . '</p>', $ok ? 200 : 401);
    })(),

    $path === '/api/data' => (static function (): void {
        header('content-type: application/json');
        echo json_encode(['ok' => true, 'at' => (int) (microtime(true) * 1000)]);
    })(),

    // SDK-04 demo: force the challenge for this route, whatever the snapshot says. In production the
    // same page is served automatically for a `challenge` verdict. Once solved, the `_cch` cookie is
    // good for an hour and this route renders normally.
    $path === '/challenge-me' => (static function () use ($cam, $ctx): void {
        $answer = $cam->serveChallenge($ctx);
        if ($answer !== null) {
            Sapi::send($answer);
            return;
        }
        page($cam, $ctx, 'Challenge passed', <<<HTML

        <p>The <code>_cch</code> cookie is set for an hour. Clear it (or open a private window) to see the check again.</p>
        HTML);
    })(),

    default => page($cam, $ctx, '404', '<p>Nothing here.</p>', 404),
};
