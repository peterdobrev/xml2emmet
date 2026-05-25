<?php
declare(strict_types=1);
namespace App\Tests;
use App\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {
    public function testReadsRequiredAndOptionalEnv(): void {
        $env = [
            'XML2EMMET_DB_USER' => 'u',
            'XML2EMMET_DB_PASS' => 'p',
        ];
        $c = Config::fromEnv($env);
        $this->assertSame('127.0.0.1', $c->dbHost);
        $this->assertSame(3306, $c->dbPort);
        $this->assertSame('xml2emmet', $c->dbName);
        $this->assertSame('u', $c->dbUser);
        $this->assertSame('p', $c->dbPass);
        $this->assertSame('xml2emmet_sid', $c->sessionName);
        $this->assertFalse($c->secureCookie);
        $this->assertFalse($c->debug);
    }

    public function testHonoursOverrides(): void {
        $env = [
            'XML2EMMET_DB_HOST'   => 'db.local',
            'XML2EMMET_DB_PORT'   => '3307',
            'XML2EMMET_DB_NAME'   => 'app',
            'XML2EMMET_DB_USER'   => 'u',
            'XML2EMMET_DB_PASS'   => 'p',
            'XML2EMMET_SECURE_COOKIE' => '1',
            'XML2EMMET_DEBUG'     => '1',
            'XML2EMMET_SESSION_NAME' => 'sid',
        ];
        $c = Config::fromEnv($env);
        $this->assertSame('db.local', $c->dbHost);
        $this->assertSame(3307, $c->dbPort);
        $this->assertSame('app', $c->dbName);
        $this->assertSame('sid', $c->sessionName);
        $this->assertTrue($c->secureCookie);
        $this->assertTrue($c->debug);
    }

    public function testMissingRequiredVarThrows(): void {
        $this->expectException(\RuntimeException::class);
        Config::fromEnv(['XML2EMMET_DB_USER' => 'u']);
    }
}
