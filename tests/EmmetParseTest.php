<?php
namespace App\Tests;
use App\Node;
use App\TransformEngine;
use App\Tests\Support\NodeAssert;
use PHPUnit\Framework\TestCase;
final class EmmetParseTest extends TestCase {
    public function testA1BareElement(): void {
        $expected = new Node('div');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('div'));
    }
    public function testA2SingleClass(): void {
        $expected = (new Node('div'))->withAttr('class', 'foo');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('div.foo'));
    }
    public function testA3IdAndClassesPreserveOrder(): void {
        $expected = (new Node('p'))->withAttr('id', 'x')->withAttr('class', 'a b');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('p#x.a.b'));
    }
}
