<?php
namespace App\Tests;
use App\Node; use App\TransformEngine;
use App\Tests\Support\NodeAssert;
use PHPUnit\Framework\TestCase;
final class ClickOpsTest extends TestCase {
    private function tree(): Node {
        return (new Node('div'))
            ->withChild(new Node('h1'))
            ->withChild((new Node('p'))->withChild(new Node('span')));
    }
    public function testSwapTag(): void {
        $expected = (new Node('div'))->withChild(new Node('h2'))
            ->withChild((new Node('p'))->withChild(new Node('span')));
        $actual = TransformEngine::applyClickOp($this->tree(), ['type'=>'swap','path'=>[0],'with'=>'h2']);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testUnwrap(): void {
        // Unwrap div[1] (the <p>) so <span> moves up to be a sibling of <h1>.
        $expected = (new Node('div'))->withChild(new Node('h1'))->withChild(new Node('span'));
        $actual = TransformEngine::applyClickOp($this->tree(), ['type'=>'unwrap','path'=>[1]]);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testWrap(): void {
        $expected = (new Node('div'))
            ->withChild((new Node('section'))->withChild(new Node('h1')))
            ->withChild((new Node('p'))->withChild(new Node('span')));
        $actual = TransformEngine::applyClickOp($this->tree(), ['type'=>'wrap','path'=>[0],'with'=>'section']);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testDelete(): void {
        $expected = (new Node('div'))->withChild((new Node('p'))->withChild(new Node('span')));
        $actual = TransformEngine::applyClickOp($this->tree(), ['type'=>'delete','path'=>[0]]);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testMove(): void {
        // Move <h1> to be the first child of <p>.
        $expected = (new Node('div'))
            ->withChild((new Node('p'))->withChild(new Node('h1'))->withChild(new Node('span')));
        $actual = TransformEngine::applyClickOp($this->tree(), ['type'=>'move','path'=>[0],'to'=>[0,0]]);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testInvalidPathThrows(): void {
        $this->expectException(\App\ClickOpError::class);
        TransformEngine::applyClickOp($this->tree(), ['type'=>'delete','path'=>[5]]);
    }
}
