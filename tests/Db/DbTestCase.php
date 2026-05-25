<?php
declare(strict_types=1);
namespace App\Tests\Db;
use App\Config;
use App\Db\Db;
use PHPUnit\Framework\TestCase;

abstract class DbTestCase extends TestCase {
    protected \PDO $pdo;

    protected function setUp(): void {
        $env = [];
        foreach (['XML2EMMET_DB_HOST','XML2EMMET_DB_PORT','XML2EMMET_DB_USER','XML2EMMET_DB_PASS'] as $k) {
            $v = getenv($k);
            if ($v !== false) $env[$k] = $v;
        }
        $env['XML2EMMET_DB_NAME'] = getenv('XML2EMMET_DB_NAME') ?: 'xml2emmet_test';
        $cfg = Config::fromEnv($env);
        $this->pdo = Db::connect($cfg);
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach (['transformations','rules','users'] as $t) {
            $this->pdo->exec("TRUNCATE TABLE $t");
        }
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    protected function insertUser(string $username = 'alice'): int {
        $stmt = $this->pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        $stmt->execute([$username, password_hash('pw_'.$username, PASSWORD_DEFAULT)]);
        return (int)$this->pdo->lastInsertId();
    }
}
