<?php
declare(strict_types=1);
namespace App\Tests\Http;

final class AuthHttpTest extends HttpTestCase {
    public function testRegisterSuccess(): void {
        [$status, , $body] = $this->post('/api/auth/register', ['username' => 'alice', 'password' => 'correct horse battery staple']);
        $this->assertSame(200, $status);
        $this->assertSame('alice', $body['user']['username']);
    }

    public function testRegisterValidationFailures(): void {
        [$s1, , $b1] = $this->post('/api/auth/register', ['username' => 'al', 'password' => 'longenough']);
        $this->assertSame(422, $s1);
        $this->assertSame('validation_failed', $b1['error']);

        [$s2, , $b2] = $this->post('/api/auth/register', ['username' => 'alice', 'password' => 'short']);
        $this->assertSame(422, $s2);
        $this->assertArrayHasKey('password', $b2['details']);
    }

    public function testRegisterDuplicate(): void {
        $this->post('/api/auth/register', ['username' => 'alice', 'password' => 'longenough']);
        [$status, , $body] = $this->post('/api/auth/register', ['username' => 'alice', 'password' => 'longenough']);
        $this->assertSame(409, $status);
        $this->assertSame('conflict', $body['error']);
    }

    public function testLoginSuccessAndWrongPassword(): void {
        $this->post('/api/auth/register', ['username' => 'alice', 'password' => 'longenough']);
        $this->cookies = [];
        [$s1, , $b1] = $this->post('/api/auth/login', ['username' => 'alice', 'password' => 'longenough']);
        $this->assertSame(200, $s1);
        $this->assertSame('alice', $b1['user']['username']);

        $this->cookies = [];
        [$s2, , $b2] = $this->post('/api/auth/login', ['username' => 'alice', 'password' => 'wrong']);
        $this->assertSame(401, $s2);
        $this->assertSame('Username or password is incorrect.', $b2['message']);
    }

    public function testMeWhileAnonymousIs401(): void {
        [$status, , $body] = $this->get('/api/auth/me');
        $this->assertSame(401, $status);
        $this->assertSame('unauthenticated', $body['error']);
    }

    public function testLogoutClearsSession(): void {
        $this->registerAndLogin();
        [$ms, , ] = $this->get('/api/auth/me');
        $this->assertSame(200, $ms);
        [$ls, , ] = $this->post('/api/auth/logout', null);
        $this->assertSame(200, $ls);
        [$ms2, , $b2] = $this->get('/api/auth/me');
        $this->assertSame(401, $ms2);
    }
}
