<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase {
    private function r(string $method, string $path): Request {
        return new Request($method, $path, [], null, [], '');
    }

    public function testStaticRouteDispatches(): void {
        $router = new Router();
        $router->add('GET', '/api/auth/me', fn(Request $r, array $p) => Response::json(200, ['hit' => true]));
        $resp = $router->dispatch($this->r('GET', '/api/auth/me'));
        $this->assertSame(200, $resp->status);
    }

    public function testParameterizedRouteCapturesId(): void {
        $router = new Router();
        $router->add('PUT', '/api/rules/{id}', fn(Request $r, array $p) => Response::json(200, ['id' => (int)$p['id']]));
        $resp = $router->dispatch($this->r('PUT', '/api/rules/42'));
        $this->assertSame('{"id":42}', $resp->body);
    }

    public function testUnknownPathReturns404Envelope(): void {
        $router = new Router();
        $resp = $router->dispatch($this->r('GET', '/nope'));
        $this->assertSame(404, $resp->status);
        $body = json_decode($resp->body, true);
        $this->assertSame('not_found', $body['error']);
    }

    public function testWrongMethodReturns405(): void {
        $router = new Router();
        $router->add('GET', '/api/x', fn() => Response::json(200, []));
        $resp = $router->dispatch($this->r('POST', '/api/x'));
        $this->assertSame(405, $resp->status);
    }

    public function testGateBlocksUnauthenticated(): void {
        $router = new Router();
        $router->add('GET', '/api/auth/me', fn() => Response::json(200, []), gate: true);
        $router->setUserId(null);
        $resp = $router->dispatch($this->r('GET', '/api/auth/me'));
        $this->assertSame(401, $resp->status);
    }

    public function testGateAllowsAuthenticated(): void {
        $router = new Router();
        $router->add('GET', '/api/auth/me', fn(Request $r, array $p, int $uid) => Response::json(200, ['uid' => $uid]), gate: true);
        $router->setUserId(7);
        $resp = $router->dispatch($this->r('GET', '/api/auth/me'));
        $this->assertSame('{"uid":7}', $resp->body);
    }
}
