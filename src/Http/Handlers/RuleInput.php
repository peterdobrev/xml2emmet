<?php
declare(strict_types=1);
namespace App\Http\Handlers;

/**
 * Validated rule create/update body. Produced by RulesHandler::validateBody
 * when every field is well-formed AND every Emmet pattern parses.
 */
final readonly class RuleInput {
    public function __construct(
        public string $name,
        public string $pattern,
        public string $replacement,
    ) {}
}
