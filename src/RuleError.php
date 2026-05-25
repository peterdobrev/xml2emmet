<?php
namespace App;

/**
 * Thrown when a replacement template references a placeholder name that was
 * not bound during the match phase (e.g. the pattern had no E1 but the
 * replacement uses E1).
 */
class RuleError extends \RuntimeException {}
