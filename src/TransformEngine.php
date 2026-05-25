<?php
namespace App;
final class TransformEngine {
    public static function emmetParse(string $abbr): Node {
        $p = new EmmetParser($abbr);
        return $p->parse();
    }

    public static function emmetEmit(Node $n, string $mode = 'html'): string {
        $out = $n->tag;
        $id      = $n->attrs['id']    ?? '';
        $classes = $n->attrs['class'] ?? '';
        if ($id !== '') {
            $out .= '#' . $id;
        }
        if ($classes !== '') {
            foreach (preg_split('/\s+/', trim($classes)) as $cls) {
                if ($cls !== '') $out .= '.' . $cls;
            }
        }
        $skip = ['id' => true, 'class' => true];
        $extras = array_filter(
            $n->attrs,
            static fn($k) => !isset($skip[$k]),
            ARRAY_FILTER_USE_KEY
        );
        if ($extras !== []) {
            $pairs = [];
            foreach ($extras as $k => $v) {
                $pairs[] = $k . '="' . $v . '"';
            }
            $out .= '[' . implode(' ', $pairs) . ']';
        }
        if ($n->text !== null) {
            $out .= '{' . $n->text . '}';
        }
        return $out;
    }
}
