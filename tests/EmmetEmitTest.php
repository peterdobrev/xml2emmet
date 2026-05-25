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
}
