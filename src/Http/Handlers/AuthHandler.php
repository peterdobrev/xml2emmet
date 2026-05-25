<?php
declare(strict_types=1);
namespace App\Http\Handlers;
use App\Db\UserStore;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Validation;

final class AuthHandler {
    public function __construct(private UserStore $users) {}

    public function register(Request $req): Response {
        $body = $req->json ?? [];
        $v = new Validation($body);
        $username = $v->requireMatch('username', '/^[A-Za-z0-9_]{3,64}$/');
        $password = $v->requireString('password', 8, 1024);
        if (!$v->ok()) return Response::error(422, 'validation_failed', 'Invalid registration.', $v->errors());
        if ($this->users->findByUsername($username) !== null) {
            return Response::error(409, 'conflict', 'Username already exists.');
        }
        $id = $this->users->create($username, $password);
        Session::login($id);
        return Response::json(200, ['user' => ['id' => $id, 'username' => $username]]);
    }

    public function login(Request $req): Response {
        $body = $req->json ?? [];
        $v = new Validation($body);
        $username = $v->requireString('username', 1, 64);
        $password = $v->requireString('password', 1, 1024);
        $vague = Response::error(401, 'unauthenticated', 'Username or password is incorrect.');
        if (!$v->ok()) return $vague;
        $user = $this->users->findByUsername($username);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return $vague;
        }
        Session::login($user['id']);
        return Response::json(200, ['user' => ['id' => $user['id'], 'username' => $user['username']]]);
    }

    public function logout(Request $req, array $params, int $userId): Response {
        Session::logout();
        return Response::json(200, ['ok' => true]);
    }

    public function me(Request $req, array $params, int $userId): Response {
        $u = $this->users->findById($userId);
        if ($u === null) return Response::error(401, 'unauthenticated', 'Session is no longer valid.');
        return Response::json(200, ['user' => ['id' => $u['id'], 'username' => $u['username']]]);
    }
}
