<?php
declare(strict_types=1);
namespace App\Tests\Http;

final class SmokeHttpTest extends HttpTestCase {
    public function testFullJourney(): void {
        [$rs, , ] = $this->post('/api/auth/register', ['username' => 'alice', 'password' => 'correct horse battery staple']);
        $this->assertSame(200, $rs);

        [$cs, , $cb] = $this->post('/api/rules', ['name' => 'ulToOl', 'pattern' => 'ul', 'replacement' => 'ol']);
        $this->assertSame(200, $cs);
        $ruleId = $cb['id'];

        [$ts, , $tb] = $this->post('/api/transform', [
            'direction' => 'xml2emmet',
            'input'     => '<ul><li>a</li></ul>',
            'settings'  => ['mode' => 'xml', 'show_text' => true, 'show_attrs' => true, 'show_attr_values' => true],
            'rule_ids'  => [$ruleId],
            'click_ops' => [],
            'save'      => true,
        ]);
        $this->assertSame(200, $ts, json_encode($tb));
        $this->assertNotNull($tb['saved_id']);

        [$hs, , $hb] = $this->get('/api/history');
        $this->assertSame(200, $hs);
        $this->assertSame(1, $hb['total']);

        $hid = $tb['saved_id'];
        [$ds, , $db] = $this->get("/api/history/$hid");
        $this->assertSame(200, $ds);
        $this->assertSame([$ruleId], $db['rule_ids']);

        [$ls, , ] = $this->post('/api/auth/logout', null);
        $this->assertSame(200, $ls);

        [$ms, , ] = $this->get('/api/auth/me');
        $this->assertSame(401, $ms);
    }
}
