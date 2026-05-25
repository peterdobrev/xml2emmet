<?php
declare(strict_types=1);
namespace App\Tests\Db;
use App\Db\UserStore;

final class UserStoreTest extends DbTestCase {
    public function testCreateAndFindByUsername(): void {
        $store = new UserStore($this->pdo);
        $id = $store->create('alice', 'correct horse battery staple');
        $this->assertGreaterThan(0, $id);
        $u = $store->findByUsername('alice');
        $this->assertSame($id, $u['id']);
        $this->assertSame('alice', $u['username']);
        $this->assertTrue(password_verify('correct horse battery staple', $u['password_hash']));
    }

    public function testFindByUsernameReturnsNullForUnknown(): void {
        $store = new UserStore($this->pdo);
        $this->assertNull($store->findByUsername('ghost'));
    }

    public function testFindById(): void {
        $store = new UserStore($this->pdo);
        $id = $store->create('alice', 'pw');
        $u = $store->findById($id);
        $this->assertSame('alice', $u['username']);
    }

    public function testCreateDuplicateUsernameThrows(): void {
        $store = new UserStore($this->pdo);
        $store->create('alice', 'pw');
        $this->expectException(\PDOException::class);
        $store->create('alice', 'pw2');
    }
}
