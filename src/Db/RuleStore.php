<?php
declare(strict_types=1);
namespace App\Db;

final class RuleStore {
    public function __construct(private \PDO $pdo) {}

    public function create(int $userId, string $name, string $pattern, string $replacement): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rules (user_id, name, pattern_emmet, replacement_emmet) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $name, $pattern, $replacement]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array{id:int,name:string,pattern_emmet:string,replacement_emmet:string,created_at:string,updated_at:string}> */
    public function listForUser(int $userId): array {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, pattern_emmet, replacement_emmet, created_at, updated_at
               FROM rules WHERE user_id = ? ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) $r['id'] = (int)$r['id'];
        return $rows;
    }

    /** @return array{id:int,name:string,pattern_emmet:string,replacement_emmet:string,created_at:string,updated_at:string}|null */
    public function findOwned(int $userId, int $id): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT id, name, pattern_emmet, replacement_emmet, created_at, updated_at
               FROM rules WHERE user_id = ? AND id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $id]);
        $r = $stmt->fetch();
        if (!$r) return null;
        $r['id'] = (int)$r['id'];
        return $r;
    }

    public function update(int $userId, int $id, string $name, string $pattern, string $replacement): bool {
        $stmt = $this->pdo->prepare(
            "UPDATE rules SET name = ?, pattern_emmet = ?, replacement_emmet = ?
              WHERE user_id = ? AND id = ?"
        );
        $stmt->execute([$name, $pattern, $replacement, $userId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM rules WHERE user_id = ? AND id = ?");
        $stmt->execute([$userId, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Verify a list of rule ids all belong to a user. Returns ids that don't. */
    public function findUnownedIds(int $userId, array $ids): array {
        if ($ids === []) return [];
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM rules WHERE id IN ($place) AND user_id = ?");
        $stmt->execute([...$ids, $userId]);
        $owned = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        return array_values(array_diff(array_map('intval', $ids), $owned));
    }
}
