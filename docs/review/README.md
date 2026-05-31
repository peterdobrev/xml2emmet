# xml2emmet — Component Review Index

**Date:** 2026-05-31  
**Agents used:** 88 | **Files analyzed:** 55

---

## Summary

| Component | Verdict | Confirmed bugs |
|-----------|---------|----------------|
| [Node + NodeEquality](review-node-nodeequality.md) | needs-work | 2 |
| [XmlParser + HtmlVoidElements](review-xml-parser.md) | needs-work | 8 |
| [EmmetParser + emmetEmit](review-emmet-parser-emit.md) | needs-work | 9 |
| [RulesEngine](review-rules-engine.md) | needs-work | 2 |
| [ClickOps](review-clickops.md) | needs-work | 2 |
| [Stats subsystem](review-stats-subsystem.md) | needs-work | 6 |
| [Config + DB layer](review-config-database-layer.md) | needs-work | 4 |
| [HTTP infrastructure](review-app-http-layer.md) | acceptable | 7 |
| [HTTP Handlers + Front controller](review-http-handlers-front-controller.md) | acceptable | 5 |
| [Frontend SPA](review-xml2emmet-frontend-spa.md) | needs-work | 9 |
| [Test suite + DevOps](review-test-suite-and-devops-setup-xml2emmet-http-api.md) | acceptable | 4 |

**Verdict key:** `solid` → `acceptable` → `needs-work` → `critical`

---

## Top issues by priority

### 🔴 Critical / data-corruption

- `emmetEmit`: `}` and `$` in text content not escaped → silent round-trip corruption
- `emmetEmit`: `"` in attribute values not escaped → silent truncation
- `emmetEmit`: `#text` synthetic nodes produce invalid Emmet → `EmmetParseError` crash
- `emmetEmit`: depth-bearing siblings not parenthesised → wrong tree on re-parse
- `EmmetParser::expandRepetition`: no `*N` cap → DoS via OOM on user-controlled input
- `ClickOps::swap()`: negative path index produces `Node` with `null` child
- `ClickOps::move()`: move-into-descendant silently corrupts tree
- `XmlParser`: numeric entities (`&#65;`) stored as literal strings (not decoded)
- `XmlParser`: boolean HTML attributes (`disabled`, `checked`) cause parse failure
- All core files missing `declare(strict_types=1)` → silent type coercion

### 🟡 Moderate

- `Router`: returns `405` error with code `not_found` instead of `method_not_allowed`
- `Session`: no CSRF protection on state-mutating endpoints
- No rate limiting on auth endpoints (register/login brute-force)
- `RulesEngine`: duplicate rule IDs silently suppress second rule
- `ClickOpError`: `__get` pattern makes `isset($e->code)` return `false`
- Frontend: XSS via `innerHTML` on server-controlled strings throughout SPA
- Frontend: no loading states, no offline handling, no error boundaries

### 🟢 Minor

- `NodeEquality::ksort` should use `SORT_STRING` flag
- Static utility classes lack `private function __construct()`
- No CI pipeline, no Dockerfile for app service
- `docker-compose.yml` installs PHP extensions on every container start
