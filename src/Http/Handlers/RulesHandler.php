<?php
declare(strict_types=1);
namespace App\Http\Handlers;

use App\Db\RuleStore;
use App\EmmetParseError;
use App\Http\Request;
use App\Http\Response;
use App\Http\Validation;
use App\TransformEngine;

final class RulesHandler {
    public function __construct(private RuleStore $rules) {}

    public function list(Request $req, array $params, int $userId): Response {
        return Response::json(200, ['items' => $this->rules->listForUser($userId)]);
    }

    public function create(Request $req, array $params, int $userId): Response {
        [$name, $pattern, $replacement, $err] = $this->validateBody($req->json ?? []);
        if ($err !== null) return $err;
        $id = $this->rules->create($userId, $name, $pattern, $replacement);
        return Response::json(200, ['id' => $id]);
    }

    public function update(Request $req, array $params, int $userId): Response {
        $id = (int)$params['id'];
        if ($this->rules->findOwned($userId, $id) === null) {
            return Response::error(404, 'not_found', 'Rule not found.');
        }
        [$name, $pattern, $replacement, $err] = $this->validateBody($req->json ?? []);
        if ($err !== null) return $err;
        $this->rules->update($userId, $id, $name, $pattern, $replacement);
        return Response::json(200, ['ok' => true]);
    }

    public function delete(Request $req, array $params, int $userId): Response {
        $id = (int)$params['id'];
        if (!$this->rules->delete($userId, $id)) {
            return Response::error(404, 'not_found', 'Rule not found.');
        }
        return Response::json(200, ['ok' => true]);
    }

    /** @return array{0:string,1:string,2:string,3:?Response} */
    private function validateBody(array $body): array {
        $v = new Validation($body);
        $name        = $v->requireString('name', 1, 128);
        $pattern     = $v->requireString('pattern', 1, 65535);
        $replacement = $v->requireString('replacement', 1, 65535);
        if (!$v->ok()) {
            return ['', '', '', Response::error(422, 'validation_failed', 'Invalid rule.', $v->errors())];
        }
        try { TransformEngine::emmetParse($pattern); }
        catch (EmmetParseError $e) {
            return ['', '', '', Response::error(422, 'parse_error', $e->getMessage(), ['field' => 'pattern'])];
        }
        try { TransformEngine::emmetParse($replacement); }
        catch (EmmetParseError $e) {
            return ['', '', '', Response::error(422, 'parse_error', $e->getMessage(), ['field' => 'replacement'])];
        }
        return [$name, $pattern, $replacement, null];
    }
}
