<?php
namespace App\Tests;
use App\Node;
use App\TransformEngine;
use App\Tests\Support\NodeAssert;
use PHPUnit\Framework\TestCase;
final class XmlParseTest extends TestCase {
    public function testC1SelfClosed(): void {
        $expected = new Node('br');
        NodeAssert::assertEquals($expected, TransformEngine::xmlParse('<br/>'));
    }
    public function testC2OpenClose(): void {
        $expected = new Node('p');
        NodeAssert::assertEquals($expected, TransformEngine::xmlParse('<p></p>'));
    }
    public function testC3AttributesPreserveOrder(): void {
        $expected = (new Node('a'))->withAttr('href','/x')->withAttr('id','y');
        NodeAssert::assertEquals($expected, TransformEngine::xmlParse('<a href="/x" id="y"/>'));
    }
}
