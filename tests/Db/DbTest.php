<?php
declare(strict_types=1);
namespace App\Tests\Db;
use App\Config;
use App\Db\Db;
use PHPUnit\Framework\TestCase;

final class DbTest extends TestCase {
    public function testConnectReturnsPdoInExceptionMode(): void {
        $cfg = Config::fromEnv($this->testEnv());
        $pdo = Db::connect($cfg);
        $this->assertInstanceOf(\PDO::class, $pdo);
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    /** @return array<string,string> */
    private function testEnv(): array {
        $env = [];
        foreach (['XML2EMMET_DB_HOST','XML2EMMET_DB_PORT','XML2EMMET_DB_USER','XML2EMMET_DB_PASS'] as $k) {
            $v = getenv($k);
            if ($v !== false) $env[$k] = $v;
        }
        $env['XML2EMMET_DB_NAME'] = getenv('XML2EMMET_DB_NAME') ?: 'xml2emmet_test';
        return $env;
    }
}
