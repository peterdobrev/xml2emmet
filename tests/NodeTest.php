<?php
namespace App\Tests;
use App\Node;
use PHPUnit\Framework\TestCase;
final class NodeTest extends TestCase {
    public function testConstructDefaults(): void {
        $n = new Node('div');
        $this->assertSame('div', $n->tag);
        $this->assertSame([], $n->attrs);
        $this->assertSame([], $n->children);
        $this->assertNull($n->text);
        $this->assertSame([], $n->appliedRules);
    }
    public function testWithChildReturnsNewNode(): void {
        $a = new Node('div');
        $b = $a->withChild(new Node('span'));
        $this->assertCount(0, $a->children);
        $this->assertCount(1, $b->children);
        $this->assertSame('span', $b->children[0]->tag);
    }
    public function testWithAttrPreservesInsertionOrder(): void {
        $n = (new Node('a'))->withAttr('href', '/x')->withAttr('id', 'y');
        $this->assertSame(['href' => '/x', 'id' => 'y'], $n->attrs);
    }
}
