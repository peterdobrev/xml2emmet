<?php
namespace App;
final class Node {
    /** @param array<string,string> $attrs @param Node[] $children @param string[] $appliedRules */
    public function __construct(
        public readonly string $tag,
        public readonly array $attrs = [],
        public readonly array $children = [],
        public readonly ?string $text = null,
        public readonly array $appliedRules = [],
    ) {}
    public function withChild(Node $c): self {
        return new self($this->tag, $this->attrs, [...$this->children, $c], $this->text, $this->appliedRules);
    }
    public function withAttr(string $k, string $v): self {
        return new self($this->tag, [...$this->attrs, $k => $v], $this->children, $this->text, $this->appliedRules);
    }
    public function withText(?string $t): self {
        return new self($this->tag, $this->attrs, $this->children, $t, $this->appliedRules);
    }
    public function withAppliedRule(string $ruleId): self {
        if (in_array($ruleId, $this->appliedRules, true)) return $this;
        return new self($this->tag, $this->attrs, $this->children, $this->text, [...$this->appliedRules, $ruleId]);
    }
}
