<?php
declare(strict_types=1);
namespace App\Tests\Http;

final class HistoryHttpTest extends HttpTestCase {
    public function testPaginationAndOrdering(): void {
        $this->registerAndLogin();
        $ids = [];
        for ($i = 0; $i < 5; $i++) $ids[] = $this->saveTransform($i);

        [, , $page1] = $this->get('/api/history', ['page' => 1, 'per_page' => 2]);
        $this->assertSame(5, $page1['total']);
        $this->assertCount(2, $page1['items']);
        $this->assertSame(end($ids), $page1['items'][0]['id']);

        [, , $page3] = $this->get('/api/history', ['page' => 3, 'per_page' => 2]);
        $this->assertCount(1, $page3['items']);
    }

    public function testDetailFetchAndCrossUser404(): void {
        $this->registerAndLogin('alice');
        $id = $this->saveTransform(1);
        [$status, , $body] = $this->get("/api/history/$id");
        $this->assertSame(200, $status);
        $this->assertSame($id, $body['id']);

        $this->cookies = [];
        $this->registerAndLogin('bob', 'longenough');
        [$status2, , ] = $this->get("/api/history/$id");
        $this->assertSame(404, $status2);
    }

    public function testPerPageClamped(): void {
        $this->registerAndLogin();
        $this->saveTransform(0);
        [, , $b] = $this->get('/api/history', ['page' => 1, 'per_page' => 9999]);
        $this->assertSame(100, $b['per_page']);
    }
}
