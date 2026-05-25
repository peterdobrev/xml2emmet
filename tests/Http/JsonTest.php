<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Http\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase {
    public function testEncodeAndDecodeRoundTrip(): void {
        $this->assertSame('{"a":1}', Json::encode(['a' => 1]));
        $this->assertSame(['a' => 1], Json::decode('{"a":1}'));
    }

    public function testDecodeReturnsNullOnInvalid(): void {
        $this->assertNull(Json::decode('not json'));
    }
}
