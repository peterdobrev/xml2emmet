<?php
namespace App\Tests;
use App\Node; use App\Rule; use App\TransformEngine;
use App\Tests\Support\NodeAssert;
use PHPUnit\Framework\TestCase;
final class ApplyRulesTest extends TestCase {
    public function testE1IdentityWhenNoMatch(): void {
        $tree = (new Node('div'))->withChild(new Node('p'));
        $rule = new Rule('r1', new Node('span'), new Node('section'));
        NodeAssert::assertEquals($tree, TransformEngine::applyRules($tree, [$rule]));
    }
    public function testE2SingleSubstitution(): void {
        $tree = (new Node('div'))->withChild(new Node('span'));
        $rule = new Rule('r1', new Node('span'), new Node('em'));
        $expected = (new Node('div'))->withChild(new Node('em'));
        $actual = TransformEngine::applyRules($tree, [$rule]);
        NodeAssert::assertEquals($expected, $actual);
    }
    public function testE3SinglePlaceholder(): void {
        // Pattern: <a><E1/></a>  Replacement: <b><E1/></b>
        $pattern = (new Node('a'))->withChild(new Node('E1'));
        $replacement = (new Node('b'))->withChild(new Node('E1'));
        $tree = (new Node('a'))->withChild((new Node('span'))->withText('hi'));
        $expected = (new Node('b'))->withChild((new Node('span'))->withText('hi'));
        NodeAssert::assertEquals($expected, TransformEngine::applyRules($tree, [new Rule('r', $pattern, $replacement)]));
    }
    public function testE4MultiplePlaceholders(): void {
        // Pattern: <wrap><E1/><E2/></wrap>  Replacement: <swap><E2/><E1/></swap>
        $pattern = (new Node('wrap'))->withChild(new Node('E1'))->withChild(new Node('E2'));
        $replacement = (new Node('swap'))->withChild(new Node('E2'))->withChild(new Node('E1'));
        $tree = (new Node('wrap'))->withChild(new Node('a'))->withChild(new Node('b'));
        $expected = (new Node('swap'))->withChild(new Node('b'))->withChild(new Node('a'));
        NodeAssert::assertEquals($expected, TransformEngine::applyRules($tree, [new Rule('r', $pattern, $replacement)]));
    }
    public function testE5DoesNotReapplyWithinSameRule(): void {
        // Rule that would otherwise infinitely rewrite itself.
        $pattern = new Node('x');
        $replacement = (new Node('x'))->withChild(new Node('x'));
        $tree = new Node('x');
        $expected = (new Node('x'))->withChild(new Node('x'));
        NodeAssert::assertEquals($expected, TransformEngine::applyRules($tree, [new Rule('r', $pattern, $replacement)]));
    }
    public function testE6RulesAppliedInOrder(): void {
        $r1 = new Rule('r1', new Node('a'), new Node('b'));
        $r2 = new Rule('r2', new Node('b'), new Node('c'));
        $tree = new Node('a');
        $expected = new Node('c');
        NodeAssert::assertEquals($expected, TransformEngine::applyRules($tree, [$r1, $r2]));
    }
    public function testE7AttributeLiteralMustMatch(): void {
        $pattern = (new Node('a'))->withAttr('rel','nofollow');
        $tree = (new Node('a'))->withAttr('rel','external');
        $rule = new Rule('r', $pattern, new Node('em'));
        NodeAssert::assertEquals($tree, TransformEngine::applyRules($tree, [$rule]));
    }
    public function testE8AttributesAndTextCarryThroughPlaceholders(): void {
        $pattern = (new Node('wrap'))->withChild(new Node('E1'));
        $replacement = (new Node('out'))->withChild(new Node('E1'));
        $tree = (new Node('wrap'))->withChild((new Node('a'))->withAttr('href','/x')->withText('link'));
        $expected = (new Node('out'))->withChild((new Node('a'))->withAttr('href','/x')->withText('link'));
        NodeAssert::assertEquals($expected, TransformEngine::applyRules($tree, [new Rule('r', $pattern, $replacement)]));
    }
}
