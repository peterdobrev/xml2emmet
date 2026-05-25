<?php
namespace App\Tests\Support;
use App\Node;
use PHPUnit\Framework\Assert;
final class NodeAssert {
    public static function assertEquals(Node $expected, Node $actual, string $path = '$'): void {
        Assert::assertSame($expected->tag, $actual->tag, "tag mismatch at $path");
        Assert::assertSame($expected->text, $actual->text, "text mismatch at $path");
        $ea = $expected->attrs; $aa = $actual->attrs;
        ksort($ea); ksort($aa);
        Assert::assertSame($ea, $aa, "attrs mismatch at $path");
        Assert::assertCount(count($expected->children), $actual->children, "children count at $path");
        foreach ($expected->children as $i => $c) {
            self::assertEquals($c, $actual->children[$i], "$path/{$c->tag}[$i]");
        }
    }
}
