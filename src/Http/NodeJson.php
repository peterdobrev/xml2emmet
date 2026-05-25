<?php
declare(strict_types=1);
namespace App\Http;
use App\Node;

final class NodeJson {
    /** @return array{tag:string,attrs:array<string,string>,text:string|null,children:list<array<string,mixed>>} */
    public static function toArray(Node $n): array {
        $children = [];
        foreach ($n->children as $c) $children[] = self::toArray($c);
        return [
            'tag'      => $n->tag,
            'attrs'    => $n->attrs,
            'text'     => $n->text,
            'children' => $children,
        ];
    }

    /** @param array<string,mixed> $arr */
    public static function fromArray(array $arr): Node {
        if (!isset($arr['tag']) || !is_string($arr['tag'])) {
            throw new \InvalidArgumentException("NodeJson: missing or invalid 'tag'");
        }
        $attrs = [];
        foreach (($arr['attrs'] ?? []) as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                throw new \InvalidArgumentException("NodeJson: attrs must be string→string");
            }
            $attrs[$k] = $v;
        }
        $text = $arr['text'] ?? null;
        if ($text !== null && !is_string($text)) {
            throw new \InvalidArgumentException("NodeJson: text must be string|null");
        }
        $children = [];
        foreach (($arr['children'] ?? []) as $c) {
            if (!is_array($c)) throw new \InvalidArgumentException("NodeJson: child must be object");
            $children[] = self::fromArray($c);
        }
        return new Node($arr['tag'], $attrs, $children, $text);
    }
}
