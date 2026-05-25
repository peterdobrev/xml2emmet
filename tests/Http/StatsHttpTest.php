<?php
declare(strict_types=1);
namespace App\Tests\Http;

final class StatsHttpTest extends HttpTestCase {
    public function testHtmlBranch(): void {
        $this->registerAndLogin();
        $html = '<div class="btn primary"><span class="btn">x</span><p>y</p></div>';
        [$status, , $body] = $this->post('/api/stats', ['kind' => 'html', 'input' => $html]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('html', $body['kind']);
        $this->assertGreaterThanOrEqual(3, $body['elements']);
        $this->assertGreaterThanOrEqual(3, $body['distinct_tags']);
        $btn = array_filter($body['top_classes'], fn($e) => $e['name'] === 'btn');
        $this->assertNotEmpty($btn);
        $this->assertSame(2, reset($btn)['count']);
        $this->assertIsArray($body['depth_histogram']);
    }

    public function testCssBranch(): void {
        $this->registerAndLogin();
        $css = '.btn { } .btn:hover { } .other .btn { }';
        [$status, , $body] = $this->post('/api/stats', ['kind' => 'css', 'input' => $css]);
        $this->assertSame(200, $status);
        $this->assertSame('css', $body['kind']);
        $this->assertSame(4, $body['class_count']);
        $names = array_column($body['top_classes'], 'name');
        $this->assertContains('btn', $names);
        $this->assertContains('other', $names);
    }

    public function testInvalidKind(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/stats', ['kind' => 'pdf', 'input' => 'x']);
        $this->assertSame(422, $status);
        $this->assertSame('validation_failed', $body['error']);
    }
}
