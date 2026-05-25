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
}
