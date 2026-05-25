<?php
declare(strict_types=1);
namespace App\Db;
use App\Config;

final class Db {
    public static function connect(Config $cfg): \PDO {
        $dsn = "mysql:host={$cfg->dbHost};port={$cfg->dbPort};dbname={$cfg->dbName};charset=utf8mb4";
        return new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
