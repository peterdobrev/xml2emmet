<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase {
    public function testJsonFactorySetsHeadersAndBody(): void {
        $r = Response::json(200, ['ok' => true]);
        $this->assertSame(200, $r->status);
        $this->assertSame('application/json; charset=utf-8', $r->headers['Content-Type']);
        $this->assertSame('{"ok":true}', $r->body);
    }

    public function testErrorFactoryStandardizesEnvelope(): void {
        $r = Response::error(422, 'validation_failed', 'bad', ['field' => 'username']);
        $this->assertSame(422, $r->status);
        $decoded = json_decode($r->body, true);
        $this->assertSame('validation_failed', $decoded['error']);
        $this->assertSame('bad', $decoded['message']);
        $this->assertSame(['field' => 'username'], $decoded['details']);
    }
}
