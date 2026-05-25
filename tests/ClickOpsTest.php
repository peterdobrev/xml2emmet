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
    public function testMoveSiblingShift(): void {
        // Tree: <div><h1/><p><span/></p></div>
        // Move <p> (path [1]) to be the first child of <h1> (target [0, 0]).
        // Expected: <div><h1><p><span/></p></h1></div>
        // Naïve delete-then-insert sees path [1] vanish, then walks [0,0] into the
        // remaining h1 — which happens to still be the right place because the source
        // index (1) is AFTER the destination's first index (0). This case must work.
        $tree = (new Node('div'))
            ->withChild(new Node('h1'))
            ->withChild((new Node('p'))->withChild(new Node('span')));
        $expected = (new Node('div'))
            ->withChild((new Node('h1'))->withChild((new Node('p'))->withChild(new Node('span'))));
        $actual = TransformEngine::applyClickOp($tree, ['type'=>'move','path'=>[1],'to'=>[0,0]]);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testMoveSourceShiftsDestination(): void {
        // Tree: <div><h1/><p/><span/></div>
        // Move <h1> (path [0]) to be the LAST child of <div> (target [3]).
        // After deleting [0], the tree has 2 children. Naïve insertAt([3], h1) is out
        // of bounds. Correct behaviour: target [3] in the original tree means "after
        // the last child" — which after deletion is index 2.
        $tree = (new Node('div'))
            ->withChild(new Node('h1'))
            ->withChild(new Node('p'))
            ->withChild(new Node('span'));
        $expected = (new Node('div'))
            ->withChild(new Node('p'))
            ->withChild(new Node('span'))
            ->withChild(new Node('h1'));
        $actual = TransformEngine::applyClickOp($tree, ['type'=>'move','path'=>[0],'to'=>[3]]);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testUnknownOpTypeThrows(): void {
        $this->expectException(\App\ClickOpError::class);
        TransformEngine::applyClickOp($this->tree(), ['type'=>'frobnicate','path'=>[0]]);
    }
    public function testSwapMissingWithThrows(): void {
        $this->expectException(\App\ClickOpError::class);
        TransformEngine::applyClickOp($this->tree(), ['type'=>'swap','path'=>[0]]);
    }
    public function testClickOpErrorCarriesCode(): void {
        try {
            TransformEngine::applyClickOp($this->tree(), ['type'=>'delete','path'=>[]]);
            $this->fail('expected ClickOpError');
        } catch (\App\ClickOpError $e) {
            $this->assertSame('root_delete', $e->code);
        }
    }
    public function testBadPathHasCode(): void {
        try {
            TransformEngine::applyClickOp($this->tree(), ['type'=>'delete','path'=>[5]]);
            $this->fail('expected ClickOpError');
        } catch (\App\ClickOpError $e) {
            $this->assertSame('bad_path', $e->code);
        }
    }
    public function testUnknownOpHasCode(): void {
        try {
            TransformEngine::applyClickOp($this->tree(), ['type'=>'frobnicate','path'=>[0]]);
            $this->fail('expected ClickOpError');
        } catch (\App\ClickOpError $e) {
            $this->assertSame('unknown_op', $e->code);
        }
    }
    public function testUnwrapRootHasCode(): void {
        try {
            TransformEngine::applyClickOp($this->tree(), ['type'=>'unwrap','path'=>[]]);
            $this->fail('expected ClickOpError');
        } catch (\App\ClickOpError $e) {
            $this->assertSame('unwrap_root', $e->code);
        }
    }
    public function testMissingWithHasCode(): void {
        try {
            TransformEngine::applyClickOp($this->tree(), ['type'=>'wrap','path'=>[0]]);
            $this->fail('expected ClickOpError');
        } catch (\App\ClickOpError $e) {
            $this->assertSame('missing_with', $e->code);
        }
    }
    public function testMissingToHasCode(): void {
        try {
            TransformEngine::applyClickOp($this->tree(), ['type'=>'move','path'=>[0]]);
            $this->fail('expected ClickOpError');
        } catch (\App\ClickOpError $e) {
            $this->assertSame('missing_to', $e->code);
        }
    }
}
