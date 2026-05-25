<?php
namespace App\Tests;
use App\Node;
use App\TransformEngine;
use App\Tests\Support\NodeAssert;
use PHPUnit\Framework\TestCase;
final class EmmetParseTest extends TestCase {
    public function testA1BareElement(): void {
        $expected = new Node('div');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('div'));
    }
    public function testA2SingleClass(): void {
        $expected = (new Node('div'))->withAttr('class', 'foo');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('div.foo'));
    }
    public function testA3IdAndClassesPreserveOrder(): void {
        $expected = (new Node('p'))->withAttr('id', 'x')->withAttr('class', 'a b');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('p#x.a.b'));
    }
    public function testA4Attribute(): void {
        $expected = (new Node('a'))->withAttr('href', '/x');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('a[href=/x]'));
    }
    public function testA5MultipleAttributesQuoted(): void {
        $expected = (new Node('input'))->withAttr('type', 'text')->withAttr('name', 'q');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('input[type="text" name="q"]'));
    }
    public function testA6Text(): void {
        $expected = (new Node('span'))->withText('hello world');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('span{hello world}'));
    }
    public function testA4bValuelessAttribute(): void {
        $expected = (new Node('input'))->withAttr('type', 'text')->withAttr('required', '');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('input[type=text required]'));
    }
    public function testA6bMultipleTextBlocksConcatenate(): void {
        $expected = (new Node('span'))->withText('helloworld');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('span{hello}{world}'));
    }
    public function testA5bQuotedValueWithEscape(): void {
        $expected = (new Node('a'))->withAttr('title', 'say "hi"');
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('a[title="say \"hi\""]'));
    }
    public function testA7Child(): void {
        $expected = (new Node('div'))->withChild(new Node('span'));
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('div>span'));
    }
    public function testA8Sibling(): void {
        $expected = (new Node('_root'))->withChild(new Node('h1'))->withChild(new Node('p'));
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('h1+p'));
    }
    public function testA9NestedChildSibling(): void {
        $div = (new Node('div'))->withChild(new Node('h1'))->withChild(new Node('p'));
        $expected = $div;
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('div>h1+p'));
    }
    public function testA10Group(): void {
        $li = fn() => (new Node('li'))->withChild(new Node('a'));
        $expected = (new Node('ul'))->withChild($li())->withChild($li());
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('ul>(li>a)+(li>a)'));
    }
    public function testA11ClimbUp(): void {
        $expected = (new Node('_root'))
            ->withChild((new Node('div'))->withChild(new Node('h1')))
            ->withChild(new Node('p'));
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('div>h1^p'));
    }
    public function testA12DoubleClimb(): void {
        $expected = (new Node('_root'))
            ->withChild((new Node('a'))->withChild((new Node('b'))->withChild(new Node('c'))))
            ->withChild(new Node('d'));
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('a>b>c^^d'));
    }
    public function testA12bExcessClimbCappedAtRoot(): void {
        $expected = (new Node('_root'))->withChild(new Node('a'))->withChild(new Node('b'));
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('a^^^^b'));
    }
    public function testA13Repetition(): void {
        $li = new Node('li');
        $expected = (new Node('_root'))->withChild($li)->withChild($li)->withChild($li);
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('li*3'));
    }
    public function testA14RepetitionWithPlaceholder(): void {
        $expected = (new Node('_root'))
            ->withChild((new Node('li'))->withText('item 1'))
            ->withChild((new Node('li'))->withText('item 2'));
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('li{item $}*2'));
    }
    public function testA15GroupRepetition(): void {
        $row = fn() => (new Node('tr'))->withChild(new Node('td'))->withChild(new Node('td'));
        $expected = (new Node('table'))->withChild($row())->withChild($row());
        NodeAssert::assertEquals($expected, TransformEngine::emmetParse('table>(tr>td*2)*2'));
    }
}
