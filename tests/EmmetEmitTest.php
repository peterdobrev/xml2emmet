<?php
namespace App\Tests;
use App\Node;
use App\TransformEngine;
use PHPUnit\Framework\TestCase;
final class EmmetEmitTest extends TestCase {
    public function testB1Bare(): void {
        $this->assertSame('div', TransformEngine::emmetEmit(new Node('div')));
    }
    public function testB2Class(): void {
        $n = (new Node('div'))->withAttr('class', 'foo bar');
        $this->assertSame('div.foo.bar', TransformEngine::emmetEmit($n));
    }
    public function testB3IdClassAndAttr(): void {
        $n = (new Node('a'))->withAttr('id','x')->withAttr('class','y')->withAttr('href','/z');
        $this->assertSame('a#x.y[href="/z"]', TransformEngine::emmetEmit($n));
    }
    public function testB4Text(): void {
        $n = (new Node('span'))->withText('hi');
        $this->assertSame('span{hi}', TransformEngine::emmetEmit($n));
    }
    public function testB5Child(): void {
        $n = (new Node('div'))->withChild(new Node('span'));
        $this->assertSame('div>span', TransformEngine::emmetEmit($n));
    }
    public function testB6Siblings(): void {
        $n = (new Node('_root'))->withChild(new Node('h1'))->withChild(new Node('p'));
        $this->assertSame('h1+p', TransformEngine::emmetEmit($n));
    }
    public function testB7ChildThenSiblings(): void {
        $n = (new Node('div'))
            ->withChild((new Node('h1'))->withText('Hi'))
            ->withChild(new Node('p'));
        $this->assertSame('div>h1{Hi}+p', TransformEngine::emmetEmit($n));
    }
    public function testB8RepetitionCollapse(): void {
        $li = new Node('li');
        $n = (new Node('_root'))->withChild($li)->withChild($li)->withChild($li);
        $this->assertSame('li*3', TransformEngine::emmetEmit($n));
    }
    public function testB9NoCollapseWhenSubtreesDiffer(): void {
        $n = (new Node('_root'))->withChild(new Node('a'))->withChild(new Node('b'));
        $this->assertSame('a+b', TransformEngine::emmetEmit($n));
    }
    public function testB10XmlModeQuotesAllAttrs(): void {
        $n = (new Node('a'))->withAttr('class','y')->withAttr('id','x');
        $this->assertSame('a[class="y" id="x"]', TransformEngine::emmetEmit($n, 'xml'));
    }
    public function testB11XmlModeText(): void {
        $n = (new Node('p'))->withText('hi');
        $this->assertSame('p{hi}', TransformEngine::emmetEmit($n, 'xml'));
    }
    public function testB12RoundTrip(): void {
        $abbr = 'div>h1.title{Hello}+ul>li*3';
        $node = TransformEngine::emmetParse($abbr);
        $emitted = TransformEngine::emmetEmit($node);
        $this->assertSame($abbr, $emitted);
    }
}
