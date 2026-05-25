<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Db\Db;
use App\Db\HistoryStore;
use App\Db\RuleStore;
use App\Db\UserStore;
use App\Http\Handlers\AuthHandler;
use App\Http\Handlers\HistoryHandler;
use App\Http\Handlers\RulesHandler;
use App\Http\Handlers\StatsHandler;
use App\Http\Handlers\TransformHandler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;

/** @var \App\Config $cfg */
$cfg = require __DIR__ . '/../config/config.php';

$requestId = bin2hex(random_bytes(8));
$start     = microtime(true);

try {
    Session::start($cfg);

    // Reject oversize bodies before any further work.
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 2_000_000) {
        $resp = Response::error(413, 'payload_too_large', 'Request body exceeds 2 MB.');
        $resp = new Response($resp->status, $resp->headers + ['X-Request-Id' => $requestId], $resp->body);
        $resp->send();
        log_line($requestId, $start, $resp->status, 'payload_too_large');
        return;
    }

    $req = Request::fromGlobals();
    $pdo = Db::connect($cfg);
    $userStore    = new UserStore($pdo);
    $ruleStore    = new RuleStore($pdo);
    $historyStore = new HistoryStore($pdo);

    $auth      = new AuthHandler($userStore);
    $transform = new TransformHandler($ruleStore, $historyStore);
    $rules     = new RulesHandler($ruleStore);
    $history   = new HistoryHandler($historyStore);
    $stats     = new StatsHandler();

    $router = new Router();
    $router->setUserId(Session::userId());

    // Auth (open)
    $router->add('POST', '/api/auth/register', fn($r) => $auth->register($r));
    $router->add('POST', '/api/auth/login',    fn($r) => $auth->login($r));
    // Auth (gated)
    $router->add('POST', '/api/auth/logout', fn($r, $p, $u) => $auth->logout($r, $p, $u),    gate: true);
    $router->add('GET',  '/api/auth/me',     fn($r, $p, $u) => $auth->me($r, $p, $u),        gate: true);

    // Transform
    $router->add('POST', '/api/transform', fn($r, $p, $u) => $transform->transform($r, $p, $u), gate: true);

    // Rules
    $router->add('GET',    '/api/rules',      fn($r, $p, $u) => $rules->list($r, $p, $u),   gate: true);
    $router->add('POST',   '/api/rules',      fn($r, $p, $u) => $rules->create($r, $p, $u), gate: true);
    $router->add('PUT',    '/api/rules/{id}', fn($r, $p, $u) => $rules->update($r, $p, $u), gate: true);
    $router->add('DELETE', '/api/rules/{id}', fn($r, $p, $u) => $rules->delete($r, $p, $u), gate: true);

    // History
    $router->add('GET', '/api/history',      fn($r, $p, $u) => $history->list($r, $p, $u),   gate: true);
    $router->add('GET', '/api/history/{id}', fn($r, $p, $u) => $history->detail($r, $p, $u), gate: true);

    // Stats
    $router->add('POST', '/api/stats', fn($r, $p, $u) => $stats->stats($r, $p, $u), gate: true);

    $resp = $router->dispatch($req);
    $resp = new Response($resp->status, $resp->headers + ['X-Request-Id' => $requestId], $resp->body);
    $resp->send();
    log_line($requestId, $start, $resp->status, $req->method . ' ' . $req->path);
} catch (\Throwable $e) {
    $details = $cfg->debug ? ['trace' => $e->getTraceAsString()] : ['request_id' => $requestId];
    $resp = Response::error(500, 'internal_error', $cfg->debug ? $e->getMessage() : 'Internal error.', $details);
    $resp = new Response($resp->status, $resp->headers + ['X-Request-Id' => $requestId], $resp->body);
    $resp->send();
    error_log("[$requestId] uncaught: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    log_line($requestId, $start, 500, 'internal_error: ' . $e->getMessage());
}

function log_line(string $rid, float $start, int $status, string $msg): void {
    $ms = (int)round((microtime(true) - $start) * 1000);
    error_log("[$rid] $status {$ms}ms $msg");
}
