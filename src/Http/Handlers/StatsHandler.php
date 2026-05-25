<?php
declare(strict_types=1);
namespace App\Http\Handlers;

use App\Http\Request;
use App\Http\Response;
use App\Http\Validation;
use App\Stats;
use App\Stats\CssClassCounter;
use App\TransformEngine;
use App\XmlParseError;

final class StatsHandler {
    public function stats(Request $req, array $params, int $userId): Response {
        $body = $req->json ?? [];
        $v = new Validation($body);
        $kind  = $v->requireEnum('kind', ['html', 'css']);
        $input = $v->requireString('input', 0, 2_000_000);
        if (!$v->ok()) return Response::error(422, 'validation_failed', 'Invalid stats request.', $v->errors());

        if ($kind === 'css') {
            $counts = CssClassCounter::count($input);
            $top = self::topClasses($counts, 100);
            return Response::json(200, [
                'kind'        => 'css',
                'class_count' => array_sum($counts),
                'top_classes' => $top,
            ]);
        }

        // HTML
        try { $tree = TransformEngine::xmlParse($input, 'html'); }
        catch (XmlParseError $e) { return Response::error(422, 'parse_error', $e->getMessage()); }

        $s = Stats::compute($tree);
        $depthHist = [];
        foreach ($s['depthHistogram'] as $d => $c) $depthHist[(string)$d] = $c;

        return Response::json(200, [
            'kind'            => 'html',
            'elements'        => $s['nodeCount'],
            'distinct_tags'   => count($s['tagHistogram']),
            'attributes'      => $s['attrCount'],
            'max_depth'       => $s['depth'],
            'top_classes'     => self::topClasses($s['classCounts'], 100),
            'depth_histogram' => $depthHist,
        ]);
    }

    /**
     * @param array<string,int> $counts
     * @return list<array{name:string,count:int}>
     */
    private static function topClasses(array $counts, int $limit): array {
        $entries = [];
        foreach ($counts as $name => $count) $entries[] = ['name' => $name, 'count' => $count];
        usort($entries, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
        return array_slice($entries, 0, $limit);
    }
}
