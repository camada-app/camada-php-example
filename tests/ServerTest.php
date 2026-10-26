<?php

declare(strict_types=1);

namespace CamadaExample\Tests;

use Camada\Transport\HttpRequest;
use Camada\Transport\StreamTransport;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * The example over a real `php -S`, against an engine whose analyst URL is a closed port: every
 * request runs cold and falls open, and the routes the app gates itself (challenge) still work
 * because the challenge kit needs no snapshot. Each test boots its own server on a fresh cache
 * dir, the lazy first-request build as in production.
 */
final class ServerTest extends TestCase
{
    private const BLOCKED_IP = '203.0.113.66';
    private const BROWSER = ['x-forwarded-for' => '198.51.100.7', 'accept' => 'text/html', 'sec-fetch-dest' => 'document'];

    /** @var resource|null */
    private $proc = null;
    private string $url = '';
    private string $dir = '';
    private string $out = '';
    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/camada-example-' . getmypid() . '-' . random_int(1, 1_000_000);
        $dead = 'http://127.0.0.1:' . self::freePort();
        $this->boot([
            'CAMADA_KEY' => 'tok-example.snap-example', 'CAMADA_INGEST_URL' => $dead, 'CAMADA_SNAPSHOT_URL' => $dead . '/snapshot',
            'CAMADA_TRUSTED_PROXY' => 'hops:1', 'CAMADA_CACHE_DIR' => $this->dir,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->proc !== null) {
            $st = proc_get_status($this->proc);
            if ($st['running']) {
                proc_terminate($this->proc, SIGTERM);
            }
            foreach ($this->pipes as $p) {
                fclose($p);
            }
            proc_close($this->proc);
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @param array<string, string> $env */
    private function boot(array $env): void
    {
        $port = self::freePort();
        $this->url = "http://127.0.0.1:{$port}";
        $root = dirname(__DIR__);
        $full = getenv();
        unset($full['CAMADA_DISABLED'], $full['PHP_CLI_SERVER_WORKERS']);
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-d', 'display_errors=stderr', '-t', $root . '/public', $root . '/public/index.php'];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), array_merge($full, $env));   // cwd elsewhere: a developer .env must not reach the suite
        Assert::assertIsResource($proc);
        $this->proc = $proc;
        $this->pipes = $pipes;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if (str_contains($this->serverOutput(), ') started')) {
                return;
            }
            usleep(20_000);
        }
        self::fail("php -S never started\n" . $this->serverOutput());
    }

    private function serverOutput(): string
    {
        foreach ($this->pipes as $p) {
            $more = stream_get_contents($p);
            if (is_string($more)) {
                $this->out .= $more;
            }
        }
        return $this->out;
    }

    private static function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        Assert::assertNotFalse($s);
        $name = stream_socket_get_name($s, false);
        fclose($s);
        Assert::assertIsString($name);
        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        // the SDK's own stream client: lower-cased header names, a 4xx is an answer, status 0 is none
        $r = (new StreamTransport())->send(new HttpRequest($method, $this->url . $path, $headers, $body, 10.0));
        Assert::assertNotSame(0, $r->status, "no answer for {$method} {$path}\n" . $this->serverOutput());
        return ['status' => $r->status, 'headers' => $r->headers, 'body' => $r->body];
    }

    public function testPagesRenderWithTheFirstPartyBeacon(): void
    {
        $first = $this->request('GET', '/');
        self::assertSame(200, $first['status']);
        self::assertStringStartsWith('_sfp=', $first['headers']['set-cookie'] ?? '');
        foreach (['/', '/pricing', '/login-form'] as $path) {
            $r = $this->request('GET', $path);
            self::assertSame(200, $r['status']);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $r['headers']['x-rid'] ?? '');
            self::assertStringContainsString('/_cam/b.js?r=' . $r['headers']['x-rid'], $r['body']);
        }
        self::assertSame('application/javascript', $this->request('GET', '/_cam/b.js')['headers']['content-type'] ?? '');
    }

    public function testApiAnswersJson(): void
    {
        $r = $this->request('GET', '/api/data');
        self::assertSame(200, $r['status']);
        self::assertTrue(json_decode($r['body'], true)['ok'] ?? false);
    }

    public function testLoginReportsTheOutcome(): void
    {
        $form = ['content-type' => 'application/x-www-form-urlencoded'];
        $bad = $this->request('POST', '/login', $form, 'user=demo%40example.com&pass=nope');
        self::assertSame(401, $bad['status']);
        self::assertStringContainsString('login failed', $bad['body']);
        $ok = $this->request('POST', '/login', $form, 'user=demo%40example.com&pass=demo');
        self::assertSame(200, $ok['status']);
        self::assertStringContainsString('login succeeded', $ok['body']);
        // a body that is not UTF-8 is a failed login, not a 500
        self::assertSame(401, $this->request('POST', '/login', $form, "user=\xff&pass=x")['status']);
        // the outcomes were spooled with a hashed uid; the raw email appears nowhere
        $deadline = microtime(true) + 5;
        do {
            $spool = (string) @file_get_contents($this->dir . '/events.ndjson');
            usleep(20_000);
        } while (substr_count($spool, '"et":') < 2 && microtime(true) < $deadline);
        self::assertStringContainsString('"et":"login_failed"', $spool);
        self::assertStringContainsString('"et":"login_succeeded"', $spool);
        self::assertStringNotContainsString('demo@example.com', $spool);
    }

    public function testEachServerBuildsItsOwnEngineFromItsEnvironment(): void
    {
        $this->request('GET', '/');
        $deadline = microtime(true) + 5;
        while (!file_exists($this->dir . '/events.ndjson') && microtime(true) < $deadline) {
            usleep(20_000);
        }
        self::assertFileExists($this->dir . '/events.ndjson', 'the engine writes its spool to the cache dir this test configured');
    }

    public function testAColdSnapshotFallsOpen(): void
    {
        $r = $this->request('GET', '/', ['x-forwarded-for' => self::BLOCKED_IP]);
        self::assertSame(200, $r['status']);
        self::assertArrayNotHasKey('x-block-reason', $r['headers']);
        self::assertArrayHasKey('x-rid', $r['headers']);
    }

    public function testChallengeMeServesThePageToABrowser(): void
    {
        $r = $this->request('GET', '/challenge-me', self::BROWSER);
        self::assertSame(403, $r['status']);
        self::assertSame('1', $r['headers']['x-camada-challenge'] ?? '');
        self::assertStringStartsWith('text/html', $r['headers']['content-type'] ?? '');
        self::assertStringContainsString('/__camada/challenge', $r['body']);
    }

    public function testChallengeMeSignalsAnApiClient(): void
    {
        $r = $this->request('GET', '/challenge-me', ['x-forwarded-for' => '198.51.100.7']);
        self::assertSame(200, $r['status']);
        self::assertSame('1', $r['headers']['x-camada-challenge'] ?? '');
        self::assertSame('no-store', $r['headers']['cache-control'] ?? '');
    }

    public function testUnknownPathRendersThe404Page(): void
    {
        $r = $this->request('GET', '/nope');
        self::assertSame(404, $r['status']);
        self::assertStringContainsString('Nothing here', $r['body']);
        self::assertArrayHasKey('x-rid', $r['headers']);
    }

    public function testNoNoiseFromTheAppWithTheAnalystDead(): void
    {
        $this->request('GET', '/');
        $this->request('GET', '/api/data');
        usleep(200_000);
        $out = $this->serverOutput();
        self::assertStringNotContainsString('PHP Warning', $out);
        self::assertStringNotContainsString('PHP Fatal', $out);
        self::assertStringNotContainsString('Uncaught', $out);
    }

    public function testTheInstalledSdkIsTheSiblingCheckout(): void
    {
        // the path repository symlinks ../camada-php: the version literal the SDK ships is the one the tests ran against
        $version = dirname(__DIR__, 2) . '/camada-php/src/Version.php';
        self::assertFileExists($version, 'no camada-php checkout beside this repo');
        self::assertSame(1, preg_match("/public const VERSION = '([^']+)';/", (string) file_get_contents($version), $m));
        self::assertSame($m[1], \Camada\Version::VERSION, 'vendor/camada/camada is behind ../camada-php: run composer update camada/camada');
        self::assertMatchesRegularExpression('~^@?[a-z0-9._-]+(/[a-z0-9._-]+)?/\d+\.\d+\.\d+[a-z0-9.-]*$~', \Camada\Version::SDK_ID);
    }
}
