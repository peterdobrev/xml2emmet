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
    public function testC4Children(): void {
        $expected = (new Node('div'))->withChild(new Node('h1'))->withChild(new Node('p'));
        NodeAssert::assertEquals($expected, TransformEngine::xmlParse('<div><h1/><p/></div>'));
    }
    public function testC5TextOnly(): void {
        $expected = (new Node('p'))->withText('hello');
        NodeAssert::assertEquals($expected, TransformEngine::xmlParse('<p>hello</p>'));
    }
    public function testC6MixedContent(): void {
        $expected = (new Node('p'))
            ->withChild((new Node('#text'))->withText('hi '))
            ->withChild(new Node('b'))
            ->withChild((new Node('#text'))->withText(' bye'));
        NodeAssert::assertEquals($expected, TransformEngine::xmlParse('<p>hi <b/> bye</p>'));
    }
    public function testC7HtmlVoidElements(): void {
        $expected = (new Node('div'))->withChild(new Node('br'))->withChild(new Node('img'));
        NodeAssert::assertEquals($expected, TransformEngine::xmlParse('<div><br><img></div>', 'html'));
    }
    public function testC8UnclosedTag(): void {
        $this->expectException(\App\XmlParseError::class);
        TransformEngine::xmlParse('<div><p></div>');
    }
    public function testC9MismatchedTags(): void {
        $this->expectException(\App\XmlParseError::class);
        TransformEngine::xmlParse('<a></b>');
    }
}
