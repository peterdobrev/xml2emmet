<?php
declare(strict_types=1);
namespace App\Db;

final class HistoryStore {
    public function __construct(private \PDO $pdo) {}

    public function insert(
        int $userId,
        string $direction,
        string $input,
        string $output,
        array $settings,
        array $ruleIds,
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO transformations (user_id, direction, input, output, settings, rule_ids)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $direction,
            $input,
            $output,
            json_encode($settings, JSON_THROW_ON_ERROR),
            json_encode(array_values(array_map('intval', $ruleIds)), JSON_THROW_ON_ERROR),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{items:list<array<string,mixed>>,page:int,per_page:int,total:int} */
    public function listForUser(int $userId, int $page, int $perPage): array {
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM transformations WHERE user_id = ?");
        $countStmt->execute([$userId]);
        $total = (int)$countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT id, direction, input, output, settings, rule_ids, created_at
               FROM transformations WHERE user_id = ?
               ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['id']       = (int)$r['id'];
            $r['settings'] = json_decode($r['settings'], true, flags: JSON_THROW_ON_ERROR);
            $r['rule_ids'] = json_decode($r['rule_ids'], true, flags: JSON_THROW_ON_ERROR);
        }
        return ['items' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function findOwned(int $userId, int $id): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT id, direction, input, output, settings, rule_ids, created_at
               FROM transformations WHERE user_id = ? AND id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $id]);
        $r = $stmt->fetch();
        if (!$r) return null;
        $r['id']       = (int)$r['id'];
        $r['settings'] = json_decode($r['settings'], true, flags: JSON_THROW_ON_ERROR);
        $r['rule_ids'] = json_decode($r['rule_ids'], true, flags: JSON_THROW_ON_ERROR);
        return $r;
    }
}
