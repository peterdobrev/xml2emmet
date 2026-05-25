<?php
declare(strict_types=1);
namespace App\Tests\Http;

final class RulesHttpTest extends HttpTestCase {
    public function testCrudFlow(): void {
        $this->registerAndLogin();

        [$cs, , $cb] = $this->post('/api/rules', ['name' => 'flat', 'pattern' => 'ul>li*', 'replacement' => 'ol>li*']);
        $this->assertSame(200, $cs);
        $id = $cb['id'];

        [$ls, , $lb] = $this->get('/api/rules');
        $this->assertSame(200, $ls);
        $this->assertCount(1, $lb['items']);
        $this->assertSame('flat', $lb['items'][0]['name']);

        [$us, , ] = $this->put("/api/rules/$id", ['name' => 'renamed', 'pattern' => 'ul>li*', 'replacement' => 'ol>li*']);
        $this->assertSame(200, $us);
        [, , $lb2] = $this->get('/api/rules');
        $this->assertSame('renamed', $lb2['items'][0]['name']);

        [$ds, , ] = $this->del("/api/rules/$id");
        $this->assertSame(200, $ds);
        [, , $lb3] = $this->get('/api/rules');
        $this->assertCount(0, $lb3['items']);
    }

    public function testCrossUserIsolation(): void {
        $this->registerAndLogin('alice');
        [$cs, , $cb] = $this->post('/api/rules', ['name' => 'x', 'pattern' => 'a', 'replacement' => 'b']);
        $aliceRule = $cb['id'];

        $this->cookies = [];
        $this->registerAndLogin('bob', 'longenough');

        [$ls, , $lb] = $this->get('/api/rules');
        $this->assertSame([], $lb['items']);

        [$us, , ] = $this->put("/api/rules/$aliceRule", ['name' => 'pwn', 'pattern' => 'a', 'replacement' => 'b']);
        $this->assertSame(404, $us);

        [$ds, , ] = $this->del("/api/rules/$aliceRule");
        $this->assertSame(404, $ds);
    }

    public function testInvalidPatternReturnsParseError(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/rules', ['name' => 'x', 'pattern' => '$$$', 'replacement' => 'b']);
        $this->assertSame(422, $status);
        $this->assertSame('parse_error', $body['error']);
        $this->assertSame('pattern', $body['details']['field']);
    }
}
