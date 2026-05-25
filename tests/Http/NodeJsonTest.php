<?php
declare(strict_types=1);
namespace App\Tests\Http;
use App\Http\NodeJson;
use App\Node;
use App\Tests\Support\NodeAssert;
use PHPUnit\Framework\TestCase;

final class NodeJsonTest extends TestCase {
    public function testToArrayProducesWireShape(): void {
        $n = (new Node('div'))->withAttr('class', 'a')
            ->withChild((new Node('p'))->withText('hi'));
        $arr = NodeJson::toArray($n);
        $this->assertSame('div', $arr['tag']);
        $this->assertSame(['class' => 'a'], $arr['attrs']);
        $this->assertNull($arr['text']);
        $this->assertCount(1, $arr['children']);
        $this->assertSame('p', $arr['children'][0]['tag']);
        $this->assertSame('hi', $arr['children'][0]['text']);
    }

    public function testToArrayExcludesAppliedRules(): void {
        $n = (new Node('div'))->withAppliedRule('rule-1');
        $arr = NodeJson::toArray($n);
        $this->assertArrayNotHasKey('appliedRules', $arr);
        $this->assertSame(['tag' => 'div', 'attrs' => [], 'text' => null, 'children' => []], $arr);
    }

    public function testFromArrayRoundTrip(): void {
        $orig = (new Node('div'))->withAttr('class', 'a')
            ->withChild((new Node('p'))->withText('hi'));
        $back = NodeJson::fromArray(NodeJson::toArray($orig));
        NodeAssert::assertEquals($orig, $back);
    }

    public function testFromArrayRejectsInvalidShape(): void {
        $this->expectException(\InvalidArgumentException::class);
        NodeJson::fromArray(['no_tag' => 'oops']);
    }
}
