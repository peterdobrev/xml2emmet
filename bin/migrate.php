<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

/** @var \App\Config $cfg */
$cfg = require __DIR__ . '/../config/config.php';

$dsn = "mysql:host={$cfg->dbHost};port={$cfg->dbPort};dbname={$cfg->dbName};charset=utf8mb4";
$pdo = new \PDO($dsn, $cfg->dbUser, $cfg->dbPass, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$dir = __DIR__ . '/../src/schema';
$files = glob($dir . '/*.sql');
sort($files, SORT_STRING);

$ranAny = false;
foreach ($files as $path) {
    $name = basename($path);
    if (isset($applied[$name])) {
        echo "skip $name (already applied)\n";
        continue;
    }
    echo "apply $name … ";
    $sql = file_get_contents($path);
    try {
        foreach (preg_split('/;\s*\n/', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
        $stmt = $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
        $stmt->execute([$name]);
        echo "ok\n";
        $ranAny = true;
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if (!$ranAny) echo "nothing to do\n";
