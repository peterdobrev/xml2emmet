<?php
declare(strict_types=1);
namespace App\Http\Handlers;

use App\ClickOpError;
use App\Db\HistoryStore;
use App\Db\RuleStore;
use App\EmmetParseError;
use App\Http\Json;
use App\Http\NodeJson;
use App\Http\Request;
use App\Http\Response;
use App\Http\Validation;
use App\Node;
use App\Rule;
use App\TransformEngine;
use App\XmlParseError;

final class TransformHandler {
    public function __construct(
        private RuleStore $rules,
        private HistoryStore $history,
    ) {}

    public function transform(Request $req, array $params, int $userId): Response {
        $body = $req->json ?? [];
        $v = new Validation($body);
        $direction = $v->requireEnum('direction', ['xml2emmet', 'emmet2xml']);
        $input     = $v->requireString('input', 0, 2_000_000);
        $settings  = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        $sv = new Validation($settings);
        $mode           = $sv->requireEnum('mode', ['xml', 'html']);
        $showText       = $sv->optionalBool('show_text', true);
        $showAttrs      = $sv->optionalBool('show_attrs', true);
        $showAttrValues = $sv->optionalBool('show_attr_values', true);
        $ruleIds = is_array($body['rule_ids'] ?? null) ? $body['rule_ids'] : [];
        $clickOps = is_array($body['click_ops'] ?? null) ? $body['click_ops'] : [];
        $save = (bool)($body['save'] ?? false);

        if (!$v->ok() || !$sv->ok()) {
            return Response::error(422, 'validation_failed', 'Invalid transform request.', $v->errors() + $sv->errors());
        }
        foreach ($ruleIds as $rid) {
            if (!is_int($rid)) return Response::error(422, 'validation_failed', 'rule_ids must be integers.', ['rule_ids' => 'each id must be int']);
        }

        // 1. Parse input
        try {
            $tree = $direction === 'xml2emmet'
                ? TransformEngine::xmlParse($input, $mode)
                : TransformEngine::emmetParse($input);
        } catch (XmlParseError | EmmetParseError $e) {
            return Response::error(422, 'parse_error', $e->getMessage());
        }

        // 2. Apply rules in order; reject foreign rule ids
        if ($ruleIds !== []) {
            $unowned = $this->rules->findUnownedIds($userId, $ruleIds);
            if ($unowned !== []) {
                return Response::error(404, 'not_found', 'Unknown rule id.', ['rule_ids' => $unowned]);
            }
            $ruleObjs = [];
            foreach ($ruleIds as $rid) {
                $row = $this->rules->findOwned($userId, (int)$rid);
                try {
                    $pat = TransformEngine::emmetParse($row['pattern_emmet']);
                    $rep = TransformEngine::emmetParse($row['replacement_emmet']);
                } catch (EmmetParseError $e) {
                    return Response::error(422, 'parse_error', "Rule {$row['id']} failed to parse: " . $e->getMessage(), ['rule_id' => $row['id']]);
                }
                $ruleObjs[] = new Rule((string)$row['id'], $pat, $rep);
            }
            $tree = TransformEngine::applyRules($tree, $ruleObjs);
        }

        // 3. Apply click-ops in order
        foreach ($clickOps as $i => $op) {
            if (!is_array($op)) {
                return Response::error(422, 'validation_failed', "click_ops[$i] must be an object", ['op_index' => $i]);
            }
            try {
                $tree = TransformEngine::applyClickOp($tree, $op);
            } catch (ClickOpError $e) {
                return Response::error(422, $e->code, $e->getMessage(), ['op_index' => $i]);
            }
        }

        // 4. Apply post-filter for Emmet emit
        $emitTree = $tree;
        if ($direction === 'xml2emmet') {
            $emitTree = self::filterTree($tree, $showText, $showAttrs, $showAttrValues);
        }

        // 5. Emit
        $output = $direction === 'xml2emmet'
            ? TransformEngine::emmetEmit($emitTree, $mode)
            : TransformEngine::xmlEmit($emitTree, $mode);

        $savedId = null;
        if ($save) {
            $savedId = $this->history->insert(
                $userId,
                $direction,
                $input,
                $output,
                ['mode' => $mode, 'show_text' => $showText, 'show_attrs' => $showAttrs, 'show_attr_values' => $showAttrValues],
                array_map('intval', $ruleIds),
            );
        }

        return Response::json(200, [
            'output'   => $output,
            'tree'     => NodeJson::toArray($emitTree),
            'saved_id' => $savedId,
        ]);
    }

    private static function filterTree(Node $n, bool $showText, bool $showAttrs, bool $showAttrValues): Node {
        $attrs = $n->attrs;
        if (!$showAttrs) {
            $attrs = [];
        } elseif (!$showAttrValues) {
            $attrs = array_fill_keys(array_keys($attrs), '');
        }
        $text = $showText ? $n->text : null;
        $children = [];
        foreach ($n->children as $c) {
            // Drop pure text nodes when text is hidden
            if (!$showText && $c->tag === '#text') continue;
            $children[] = self::filterTree($c, $showText, $showAttrs, $showAttrValues);
        }
        return new Node($n->tag, $attrs, $children, $text, $n->appliedRules);
    }
}
