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
}
