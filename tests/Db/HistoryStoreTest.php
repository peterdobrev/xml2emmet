<?php
declare(strict_types=1);
namespace App\Tests\Db;
use App\Db\HistoryStore;

final class HistoryStoreTest extends DbTestCase {
    public function testInsertAndPaginate(): void {
        $uid = $this->insertUser();
        $store = new HistoryStore($this->pdo);
        for ($i = 0; $i < 5; $i++) {
            $store->insert($uid, 'xml2emmet', "<x>$i</x>", "x{$i}", ['mode'=>'xml','show_text'=>true,'show_attrs'=>true,'show_attr_values'=>true], []);
        }
        $page1 = $store->listForUser($uid, 1, 2);
        $this->assertSame(5, $page1['total']);
        $this->assertCount(2, $page1['items']);
        // newest first: input "<x>4</x>" comes first
        $this->assertSame('<x>4</x>', $page1['items'][0]['input']);

        $page3 = $store->listForUser($uid, 3, 2);
        $this->assertCount(1, $page3['items']);
    }

    public function testFindOwnedAndCrossUser(): void {
        $a = $this->insertUser('a');
        $b = $this->insertUser('b');
        $store = new HistoryStore($this->pdo);
        $id = $store->insert($a, 'xml2emmet', '<x/>', 'x', ['mode'=>'xml','show_text'=>true,'show_attrs'=>true,'show_attr_values'=>true], [42]);
        $this->assertNotNull($store->findOwned($a, $id));
        $this->assertNull($store->findOwned($b, $id));
    }

    public function testSettingsAndRuleIdsRoundTripAsJson(): void {
        $uid = $this->insertUser();
        $store = new HistoryStore($this->pdo);
        $settings = ['mode'=>'html','show_text'=>false,'show_attrs'=>true,'show_attr_values'=>false];
        $id = $store->insert($uid, 'emmet2xml', 'div>p', '<div><p></p></div>', $settings, [1, 2, 3]);
        $row = $store->findOwned($uid, $id);
        $this->assertSame($settings, $row['settings']);
        $this->assertSame([1, 2, 3], $row['rule_ids']);
    }
}
