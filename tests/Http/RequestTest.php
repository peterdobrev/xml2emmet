<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase {
    public function testParsesMethodPathQuery(): void {
        $r = new Request('GET', '/api/rules', ['page' => '2'], null, [], '');
        $this->assertSame('GET', $r->method);
        $this->assertSame('/api/rules', $r->path);
        $this->assertSame('2', $r->query['page']);
        $this->assertNull($r->json);
    }

    public function testDecodesJsonBody(): void {
        $body = '{"username":"alice"}';
        $r = new Request('POST', '/api/auth/login', [], null, ['Content-Type' => 'application/json'], $body);
        $this->assertSame(['username' => 'alice'], $r->json);
    }

    public function testInvalidJsonLeavesJsonNullAndStoresRawBody(): void {
        $r = new Request('POST', '/x', [], null, ['Content-Type' => 'application/json'], 'not json');
        $this->assertNull($r->json);
        $this->assertSame('not json', $r->rawBody);
    }
}
