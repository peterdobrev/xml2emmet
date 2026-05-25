<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Tests\Db\DbTestCase;

abstract class HttpTestCase extends DbTestCase {
    /** @var resource|null */
    protected static $serverProc = null;
    /** @var array<int,resource> */
    protected static array $serverPipes = [];
    protected static string $baseUrl = '';

    /** @var array<string,string> */
    protected array $cookies = [];

    public static function setUpBeforeClass(): void {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$sock) self::fail("could not bind: $errstr ($errno)");
        $name = stream_socket_get_name($sock, false);
        $port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($sock);

        $env = getenv();
        $env['XML2EMMET_DB_NAME'] = getenv('XML2EMMET_DB_NAME') ?: 'xml2emmet_test';
        $cmd = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(__DIR__ . '/../../public'));
        $descs = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descs, $pipes, null, $env);
        if (!is_resource($proc)) self::fail('failed to start php -S');
        self::$serverProc  = $proc;
        self::$serverPipes = $pipes;
        self::$baseUrl     = "http://127.0.0.1:$port";

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $c = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.2);
            if ($c) { fclose($c); return; }
            usleep(50_000);
        }
        self::fail('php -S did not become ready');
    }

    public static function tearDownAfterClass(): void {
        if (is_resource(self::$serverProc)) {
            proc_terminate(self::$serverProc, 9);
            foreach (self::$serverPipes as $p) if (is_resource($p)) fclose($p);
            proc_close(self::$serverProc);
            self::$serverProc = null;
        }
    }

    protected function setUp(): void {
        parent::setUp();
        $this->cookies = [];
    }

    /** @return array{0:int,1:array<string,string>,2:mixed} */
    protected function request(string $method, string $path, mixed $body = null, array $query = []): array {
        $url = self::$baseUrl . $path;
        if ($query !== []) $url .= '?' . http_build_query($query);
        $ch  = curl_init($url);
        $hdr = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->cookies !== []) {
            $hdr[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_HEADER          => true,
            CURLOPT_HTTPHEADER      => $hdr,
            CURLOPT_TIMEOUT         => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr($raw, 0, $hSize);
        $rawBody    = substr($raw, $hSize);

        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $p = strpos($line, ':');
            if ($p === false) continue;
            $headers[trim(substr($line, 0, $p))] = trim(substr($line, $p + 1));
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($line, 11));
                $eq = strpos($cookie, '=');
                $sc = strpos($cookie, ';');
                if ($eq !== false) {
                    $name  = substr($cookie, 0, $eq);
                    $value = $sc !== false ? substr($cookie, $eq + 1, $sc - $eq - 1) : substr($cookie, $eq + 1);
                    if ($value === '' || $value === 'deleted') {
                        unset($this->cookies[$name]);
                    } else {
                        $this->cookies[$name] = $value;
                    }
                }
            }
        }
        $decoded = json_decode($rawBody, true);
        return [$code, $headers, $decoded];
    }

    protected function post(string $path, mixed $body): array  { return $this->request('POST',   $path, $body); }
    protected function get(string $path, array $query = []): array { return $this->request('GET',  $path, null, $query); }
    protected function put(string $path, mixed $body): array   { return $this->request('PUT',    $path, $body); }
    protected function del(string $path): array                { return $this->request('DELETE', $path, null); }

    protected function registerAndLogin(string $username = 'alice', string $password = 'correct horse battery staple'): int {
        [$status, , $body] = $this->post('/api/auth/register', ['username' => $username, 'password' => $password]);
        $this->assertSame(200, $status, "register failed: " . json_encode($body));
        return (int)$body['user']['id'];
    }
}
