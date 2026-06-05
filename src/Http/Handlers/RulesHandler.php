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
        $input = $this->validateBody($req->json ?? []);
        if ($input instanceof Response) return $input;
        $id = $this->rules->create($userId, $input->name, $input->pattern, $input->replacement);
        return Response::json(200, ['id' => $id]);
    }

    public function update(Request $req, array $params, int $userId): Response {
        $id = (int)$params['id'];
        if ($this->rules->findOwned($userId, $id) === null) {
            return Response::notFound('Rule not found.');
        }
        $input = $this->validateBody($req->json ?? []);
        if ($input instanceof Response) return $input;
        $this->rules->update($userId, $id, $input->name, $input->pattern, $input->replacement);
        return Response::json(200, ['ok' => true]);
    }

    public function delete(Request $req, array $params, int $userId): Response {
        $id = (int)$params['id'];
        if (!$this->rules->delete($userId, $id)) {
            return Response::notFound('Rule not found.');
        }
        return Response::json(200, ['ok' => true]);
    }

    /**
     * Returns a RuleInput on success, or a Response describing the first
     * validation/parse failure encountered. Pattern and replacement must
     * each be syntactically-valid Emmet — checked here so handlers don't
     * have to repeat the try/catch.
     */
    private function validateBody(array $body): RuleInput|Response {
        $v = new Validation($body);
        $name        = $v->requireString('name', 1, 128);
        $pattern     = $v->requireString('pattern', 1, 65535);
        $replacement = $v->requireString('replacement', 1, 65535);
        if (!$v->ok()) {
            return Response::validationFailed('Invalid rule.', $v->errors());
        }
        foreach (['pattern' => $pattern, 'replacement' => $replacement] as $field => $value) {
            try {
                TransformEngine::emmetParse($value);
            } catch (EmmetParseError $e) {
                return Response::parseError($e, ['field' => $field]);
            }
        }
        return new RuleInput($name, $pattern, $replacement);
    }
}
