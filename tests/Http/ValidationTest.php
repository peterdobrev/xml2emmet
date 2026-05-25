<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Http\Validation;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase {
    public function testRequireString(): void {
        $v = new Validation(['name' => 'alice']);
        $this->assertSame('alice', $v->requireString('name'));
        $this->assertSame([], $v->errors());
    }

    public function testMissingFieldRecordsError(): void {
        $v = new Validation([]);
        $v->requireString('name');
        $this->assertArrayHasKey('name', $v->errors());
    }

    public function testRequireIntInRange(): void {
        $v = new Validation(['x' => 5]);
        $this->assertSame(5, $v->requireInt('x', 0, 10));
        $this->assertSame([], $v->errors());

        $v2 = new Validation(['x' => 50]);
        $v2->requireInt('x', 0, 10);
        $this->assertArrayHasKey('x', $v2->errors());
    }

    public function testRequireMatch(): void {
        $v = new Validation(['u' => 'alice_99']);
        $this->assertSame('alice_99', $v->requireMatch('u', '/^[A-Za-z0-9_]{3,64}$/'));
        $this->assertSame([], $v->errors());

        $v2 = new Validation(['u' => 'al']);
        $v2->requireMatch('u', '/^[A-Za-z0-9_]{3,64}$/');
        $this->assertArrayHasKey('u', $v2->errors());
    }

    public function testRequireEnum(): void {
        $v = new Validation(['k' => 'html']);
        $this->assertSame('html', $v->requireEnum('k', ['html', 'css']));
        $v2 = new Validation(['k' => 'pdf']);
        $v2->requireEnum('k', ['html', 'css']);
        $this->assertArrayHasKey('k', $v2->errors());
    }
}
