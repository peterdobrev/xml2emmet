<?php
namespace App;
final class Rule {
    public function __construct(
        public readonly string $id,
        public readonly Node $pattern,
        public readonly Node $replacement,
    ) {}
}
