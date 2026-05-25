<?php
declare(strict_types=1);
namespace App\Tests\Db;
use App\Db\RuleStore;

final class RuleStoreTest extends DbTestCase {
    public function testCreateListUpdateDelete(): void {
        $uid = $this->insertUser();
        $store = new RuleStore($this->pdo);

        $id = $store->create($uid, 'flat lists', 'ul>li*', 'ol>li*');
        $this->assertGreaterThan(0, $id);

        $rules = $store->listForUser($uid);
        $this->assertCount(1, $rules);
        $this->assertSame('flat lists', $rules[0]['name']);

        $store->update($uid, $id, 'flat to ordered', 'ul>li*', 'ol>li*');
        $r = $store->findOwned($uid, $id);
        $this->assertSame('flat to ordered', $r['name']);

        $store->delete($uid, $id);
        $this->assertNull($store->findOwned($uid, $id));
    }

    public function testCrossUserIsolation(): void {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');
        $store = new RuleStore($this->pdo);
        $idA = $store->create($a, 'mine', 'ul>li*', 'ol>li*');
        $this->assertNull($store->findOwned($b, $idA));
        $this->assertSame(0, count($store->listForUser($b)));
        $this->assertFalse($store->update($b, $idA, 'pwn', 'ul>li*', 'ol>li*'));
        $this->assertFalse($store->delete($b, $idA));
    }
}
