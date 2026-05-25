<?php
declare(strict_types=1);
namespace App\Http;

final class Router {
    /** @var list<array{method:string,regex:string,params:list<string>,handler:\Closure,gate:bool}> */
    private array $routes = [];
    private ?int $userId = null;

    public function setUserId(?int $uid): void { $this->userId = $uid; }

    public function add(string $method, string $pattern, \Closure $handler, bool $gate = false): void {
        $params = [];
        $regex = preg_replace_callback('/\{([A-Za-z_]+)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
            'gate'    => $gate,
        ];
    }

    public function dispatch(Request $req): Response {
        $matchedPath = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $req->path, $m)) continue;
            $matchedPath = true;
            if ($r['method'] !== $req->method) continue;
            if ($r['gate'] && $this->userId === null) {
                return Response::error(401, 'unauthenticated', 'Authentication required.');
            }
            $params = [];
            foreach ($r['params'] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            return ($r['handler'])($req, $params, $this->userId ?? 0);
        }
        if ($matchedPath) {
            return Response::error(405, 'not_found', 'Method not allowed for this path.');
        }
        return Response::error(404, 'not_found', 'Route not found.');
    }
}
