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

    public function testWithChildrenReplacesEntireList(): void {
        $a = (new Node('ul'))->withChild(new Node('li'));
        $b = $a->withChildren([new Node('p'), new Node('span')]);
        $this->assertCount(1, $a->children);             // original untouched
        $this->assertCount(2, $b->children);
        $this->assertSame('p',    $b->children[0]->tag);
        $this->assertSame('span', $b->children[1]->tag);
    }

    public function testWithAppliedRuleAppendsAndDedupes(): void {
        $n = new Node('div');
        $a = $n->withAppliedRule('r1');
        $this->assertSame([],     $n->appliedRules);     // original untouched
        $this->assertSame(['r1'], $a->appliedRules);

        $b = $a->withAppliedRule('r2');
        $this->assertSame(['r1', 'r2'], $b->appliedRules);

        // Re-applying r1 returns the same instance (short-circuit dedup).
        $c = $b->withAppliedRule('r1');
        $this->assertSame($b, $c);
    }
}
