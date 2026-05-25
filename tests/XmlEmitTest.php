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
}
