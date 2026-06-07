<?php
declare(strict_types=1);
namespace App\Tests\Http;

final class TransformHttpTest extends HttpTestCase {
    public function testHappyPathXmlToEmmet(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload([
            'input' => '<ul><li>a</li><li>b</li></ul>',
        ]));
        $this->assertSame(200, $status, json_encode($body));
        $this->assertNotEmpty($body['output']);
        $this->assertSame('ul', $body['tree']['tag']);
        $this->assertNull($body['saved_id']);
    }

    public function testHappyPathEmmetToXml(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload([
            'direction' => 'emmet2xml',
            'input'     => 'div>p{hi}',
            'settings'  => $this->defaultSettings('html'),
        ]));
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<div>', $body['output']);
        $this->assertStringContainsString('hi', $body['output']);
    }

    public function testShowAttrsToggle(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload([
            'input'    => '<div class="a" id="b"/>',
            'settings' => ['mode' => 'xml', 'show_text' => true, 'show_attrs' => false, 'show_attr_values' => true],
        ]));
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('class', $body['output']);
        $this->assertStringNotContainsString('id', $body['output']);
    }

    public function testClickOpAppliesAfterRules(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload([
            'input'     => '<ul><li>a</li><li>b</li></ul>',
            'click_ops' => [[ 'type' => 'rename', 'path' => [], 'with' => 'ol' ]],
        ]));
        $this->assertSame(200, $status);
        $this->assertStringStartsWith('ol', $body['output']);
    }

    public function testSaveCreatesHistoryRow(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload(['save' => true]));
        $this->assertSame(200, $status);
        $this->assertIsInt($body['saved_id']);
        [, , $list] = $this->get('/api/history');
        $this->assertSame(1, $list['total']);
        $this->assertSame($body['saved_id'], $list['items'][0]['id']);
    }

    public function testParseErrorReturns422(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload(['input' => '<<<not xml']));
        $this->assertSame(422, $status);
        $this->assertSame('parse_error', $body['error']);
    }

    public function testBadClickOpPathReturnsOpIndex(): void {
        $this->registerAndLogin();
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload([
            'click_ops' => [[ 'type' => 'delete', 'path' => [99] ]],
        ]));
        $this->assertSame(422, $status);
        $this->assertSame('bad_path', $body['error']);
        $this->assertSame(0, $body['details']['op_index']);
    }

    public function testForeignRuleIdReturns404(): void {
        $this->registerAndLogin('alice');
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload([
            'rule_ids' => [9999],
        ]));
        $this->assertSame(404, $status);
        $this->assertSame('not_found', $body['error']);
    }

    public function testPayloadTooLarge(): void {
        $this->registerAndLogin();
        $bigInput = str_repeat('<x/>', 600_000);
        [$status, , $body] = $this->post('/api/transform', $this->transformPayload(['input' => $bigInput]));
        $this->assertSame(413, $status);
        $this->assertSame('payload_too_large', $body['error']);
    }
}
