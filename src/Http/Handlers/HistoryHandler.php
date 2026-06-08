<?php
declare(strict_types=1);
namespace App\Http\Handlers;

use App\Aws\HistoryExporter;
use App\Db\HistoryStore;
use App\Http\Request;
use App\Http\Response;

final class HistoryHandler {
    public function __construct(
        private HistoryStore     $history,
        private ?HistoryExporter $exporter = null,
    ) {}

    public function list(Request $req, array $params, int $userId): Response {
        $page    = max(1, (int)($req->query['page']     ?? 1));
        $perPage = (int)($req->query['per_page'] ?? 50);
        $perPage = max(1, min(100, $perPage));
        $result  = $this->history->listForUser($userId, $page, $perPage);
        return Response::json(200, $result);
    }

    public function detail(Request $req, array $params, int $userId): Response {
        $id  = (int)$params['id'];
        $row = $this->history->findOwned($userId, $id);
        if ($row === null) return Response::notFound('History entry not found.');
        return Response::json(200, $row);
    }

    public function export(Request $req, array $params, int $userId): Response {
        if ($this->exporter === null) {
            return Response::error(503, 'not_configured', 'S3 export is not configured on this instance.');
        }
        $result = $this->history->listForUser($userId, 1, 1000);
        $url    = $this->exporter->export($userId, $result['items']);
        return Response::json(200, ['download_url' => $url, 'expires_in' => 3600]);
    }
}
