<?php
namespace App\Tests;
use App\Node;
use App\TransformEngine;
use PHPUnit\Framework\TestCase;
final class XmlEmitTest extends TestCase {
    public function testD1SelfClosed(): void {
        $this->assertSame('<br/>', TransformEngine::xmlEmit(new Node('br')));
    }
    public function testD2WithText(): void {
        $n = (new Node('p'))->withText('hi');
        $this->assertSame('<p>hi</p>', TransformEngine::xmlEmit($n));
    }
    public function testD3AttributesPreserveOrder(): void {
        $n = (new Node('a'))->withAttr('href','/x')->withAttr('id','y');
        $this->assertSame('<a href="/x" id="y"/>', TransformEngine::xmlEmit($n));
    }
    public function testD4Children(): void {
        $n = (new Node('div'))->withChild(new Node('h1'))->withChild(new Node('p'));
        $this->assertSame('<div><h1/><p/></div>', TransformEngine::xmlEmit($n));
    }
    public function testD5MixedContent(): void {
        $n = (new Node('p'))
            ->withChild((new Node('#text'))->withText('hi '))
            ->withChild(new Node('b'))
            ->withChild((new Node('#text'))->withText(' bye'));
        $this->assertSame('<p>hi <b/> bye</p>', TransformEngine::xmlEmit($n));
    }
    public function testD6HtmlVoidElement(): void {
        $n = (new Node('div'))->withChild(new Node('br'));
        $this->assertSame('<div><br></div>', TransformEngine::xmlEmit($n, 'html'));
    }
    public function testD7XmlRoundTrip(): void {
        $src = '<div><h1>Hi</h1><p class="x">Lorem</p></div>';
        $node = TransformEngine::xmlParse($src);
        $this->assertSame($src, TransformEngine::xmlEmit($node));
    }
    public function testD8HtmlRoundTrip(): void {
        $src = '<div><br><img src="x.png"></div>';
        $node = TransformEngine::xmlParse($src, 'html');
        $this->assertSame($src, TransformEngine::xmlEmit($node, 'html'));
    }
}
