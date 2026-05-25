<?php
declare(strict_types=1);
namespace App\Db;

final class UserStore {
    public function __construct(private \PDO $pdo) {}

    public function create(string $username, string $password): int {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        $stmt->execute([$username, $hash]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array{id:int,username:string,password_hash:string,created_at:string}|null */
    public function findByUsername(string $username): ?array {
        $stmt = $this->pdo->prepare("SELECT id, username, password_hash, created_at FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['id'] = (int)$row['id'];
        return $row;
    }

    /** @return array{id:int,username:string,password_hash:string,created_at:string}|null */
    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT id, username, password_hash, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['id'] = (int)$row['id'];
        return $row;
    }
}
