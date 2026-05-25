<?php
namespace App\Tests;

use App\Node;
use App\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase {
    public function testF1SingleNode(): void {
        $s = Stats::compute(new Node('div'));
        $this->assertSame(1, $s['nodeCount']);
        $this->assertSame(1, $s['depth']);
        $this->assertSame(['div' => 1], $s['tagHistogram']);
    }

    public function testF2NestedTreeDepth(): void {
        $tree = (new Node('a'))->withChild((new Node('b'))->withChild(new Node('c')));
        $s = Stats::compute($tree);
        $this->assertSame(3, $s['nodeCount']);
        $this->assertSame(3, $s['depth']);
    }

    public function testF3Histogram(): void {
        $tree = (new Node('div'))->withChild(new Node('p'))->withChild(new Node('p'));
        $s = Stats::compute($tree);
        $this->assertSame(['div' => 1, 'p' => 2], $s['tagHistogram']);
    }

    public function testF4AttrCount(): void {
        $tree = (new Node('a'))->withAttr('href','/x')->withAttr('id','y')
            ->withChild((new Node('b'))->withAttr('rel','external'));
        $s = Stats::compute($tree);
        $this->assertSame(3, $s['attrCount']);
    }

    public function testF5TextLength(): void {
        $tree = (new Node('p'))
            ->withChild((new Node('#text'))->withText('hi '))
            ->withChild((new Node('b'))->withText('bold'))
            ->withChild((new Node('#text'))->withText(' bye'));
        $s = Stats::compute($tree);
        $this->assertSame(strlen('hi ') + strlen('bold') + strlen(' bye'), $s['textLength']);
    }

    public function testClassCountsAggregatesAcrossClassAttribute(): void {
        // <div class="btn primary"><span class="btn"/></div>
        $tree = (new Node('div'))->withAttr('class', 'btn primary')
            ->withChild((new Node('span'))->withAttr('class', 'btn'));
        $stats = Stats::compute($tree);
        $this->assertSame(['btn' => 2, 'primary' => 1], $stats['classCounts']);
    }

    public function testClassCountsIgnoresEmptyAndWhitespace(): void {
        $tree = (new Node('div'))->withAttr('class', '   foo   bar  ');
        $stats = Stats::compute($tree);
        $this->assertSame(['foo' => 1, 'bar' => 1], $stats['classCounts']);
    }

    public function testClassCountsWhitespaceOnlyClassYieldsNothing(): void {
        $tree = (new Node('div'))->withAttr('class', '   ');
        $stats = Stats::compute($tree);
        $this->assertSame([], $stats['classCounts']);
    }

    public function testDepthHistogramExcludesTextNodes(): void {
        // <p>hi<b/></p> — #text and <b> are both at depth 2, but only <b> counts.
        $tree = (new Node('p'))
            ->withChild((new Node('#text'))->withText('hi'))
            ->withChild(new Node('b'));
        $stats = Stats::compute($tree);
        $this->assertSame([1 => 1, 2 => 1], $stats['depthHistogram']);
    }

    public function testDepthHistogramCountsNodesPerDepth(): void {
        // root (depth 1) > [a (2), b (2) > c (3)]
        $tree = (new Node('root'))
            ->withChild(new Node('a'))
            ->withChild((new Node('b'))->withChild(new Node('c')));
        $stats = Stats::compute($tree);
        $this->assertSame([1 => 1, 2 => 2, 3 => 1], $stats['depthHistogram']);
    }

    public function testExistingKeysStillPresent(): void {
        $tree = (new Node('div'))->withChild(new Node('p'));
        $stats = Stats::compute($tree);
        $this->assertSame(2, $stats['nodeCount']);
        $this->assertSame(2, $stats['depth']);
        $this->assertArrayHasKey('tagHistogram', $stats);
        $this->assertArrayHasKey('attrCount', $stats);
        $this->assertArrayHasKey('textLength', $stats);
    }
}
