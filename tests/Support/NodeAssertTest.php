<?php
namespace App\Tests\Support;
use App\Node;
use PHPUnit\Framework\TestCase;
final class NodeAssertTest extends TestCase {
    public function testEqualTreesPass(): void {
        $a = (new Node('a'))->withAttr('x','1')->withAttr('y','2');
        $b = (new Node('a'))->withAttr('y','2')->withAttr('x','1');
        NodeAssert::assertEquals($a, $b); // attr order ignored
        $this->expectNotToPerformAssertions();
    }
    public function testDifferentTagsFail(): void {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        NodeAssert::assertEquals(new Node('a'), new Node('b'));
    }
    public function testDifferentChildrenOrderFails(): void {
        $a = (new Node('p'))->withChild(new Node('a'))->withChild(new Node('b'));
        $b = (new Node('p'))->withChild(new Node('b'))->withChild(new Node('a'));
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        NodeAssert::assertEquals($a, $b);
    }
}
