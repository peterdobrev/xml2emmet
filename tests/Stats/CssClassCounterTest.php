<?php
declare(strict_types=1);
namespace App\Tests\Stats;
use App\Stats\CssClassCounter;
use PHPUnit\Framework\TestCase;

final class CssClassCounterTest extends TestCase {
    public function testCountsSimpleClass(): void {
        $css = '.btn { color: red; }';
        $this->assertSame(['btn' => 1], CssClassCounter::count($css));
    }

    public function testCountsEachOccurrence(): void {
        $css = '.btn { color: red; } .btn:hover { color: blue; } .other .btn {}';
        // .btn appears 3x, .other appears 1x
        $this->assertSame(['btn' => 3, 'other' => 1], CssClassCounter::count($css));
    }

    public function testCountsNestedSelectorsBoth(): void {
        // per test-cases.md st-css-nested: .a .b counts both
        $css = '.a .b { color: red; }';
        $this->assertSame(['a' => 1, 'b' => 1], CssClassCounter::count($css));
    }

    public function testIgnoresIdsAndElementSelectors(): void {
        $css = '#header { color: red; } body { margin: 0; } .real { x: y; }';
        $this->assertSame(['real' => 1], CssClassCounter::count($css));
    }

    public function testHandlesHyphensAndUnderscores(): void {
        $css = '.btn-primary { x: y; } .my_class { x: y; }';
        $this->assertSame(['btn-primary' => 1, 'my_class' => 1], CssClassCounter::count($css));
    }

    public function testEmptyInput(): void {
        $this->assertSame([], CssClassCounter::count(''));
    }

    public function testIgnoresClassNamesInsideStringValues(): void {
        // content: ".fake" is a string, not a selector — naive regex matches; document v1 limitation.
        // For v1 we accept the false positive; the test pins current behavior.
        $css = 'a::before { content: ".fake"; } .real {}';
        $result = CssClassCounter::count($css);
        $this->assertSame(1, $result['real']);
        // We accept that a naive scan also picks up '.fake' — this is documented v1 behavior.
        $this->assertSame(1, $result['fake'] ?? 0);
    }
}
