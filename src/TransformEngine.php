<?php
declare(strict_types=1);
namespace App;

/**
 * Façade over the parser/emitter pipeline.
 *
 * Public surface (parse → transform → emit) is intentionally thin so that
 * the engine pieces — XmlParser, EmmetParser, RulesEngine, ClickOps,
 * XmlEmitter, EmmetEmitter — can be evolved independently.
 */
final class TransformEngine {
    private function __construct() {}

    public static function emmetParse(string $abbr): Node {
        return (new EmmetParser($abbr))->parse();
    }

    public static function xmlParse(string $src, string $mode = 'xml'): Node {
        return (new XmlParser($src, $mode))->parse();
    }

    public static function xmlEmit(Node $n, string $mode = 'xml'): string {
        return XmlEmitter::emit($n, $mode);
    }

    public static function emmetEmit(Node $n, string $mode = 'html'): string {
        return EmmetEmitter::emit($n, $mode);
    }

    /**
     * Apply a list of rules to the tree, returning a new (possibly transformed) tree.
     *
     * @param Rule[] $rules
     */
    public static function applyRules(Node $root, array $rules): Node {
        return RulesEngine::apply($root, $rules);
    }

    /**
     * Apply a single click-op to the tree, returning a new tree.
     *
     * @param array{type: string, path: int[], with?: string, to?: int[]} $op
     */
    public static function applyClickOp(Node $root, array $op): Node {
        return ClickOps::apply($root, $op);
    }
}
