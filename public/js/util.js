/**
 * Shared frontend helpers.
 *
 * Keep this file dependency-free — every panel imports from it, and a cycle
 * here would surface as a confusing module-load order bug.
 */

/**
 * Escape a value for safe inclusion in HTML. Coerces non-strings via String()
 * so callers can pass through history rows / API payloads without thinking.
 */
export function escHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Render a server-side validation/parse error inline. Looks at the response
 * code from api.js and writes into the matching `.error-msg` element.
 *
 * fieldMap maps server-side field names → CSS selectors of the error elements.
 * Returns true if the response was an error and got rendered, false otherwise.
 *
 * Example:
 *   if (!res.ok) applyServerErrors(container, res, {
 *     name:        '#name-error',
 *     pattern:     '#pattern-error',
 *     replacement: '#replacement-error',
 *   });
 */
export function applyServerErrors(container, res, fieldMap, fallbackSelector = '#form-error') {
  if (res.ok) return false;
  if (res.code === 'parse_error' && res.details?.field && fieldMap[res.details.field]) {
    container.querySelector(fieldMap[res.details.field]).textContent = res.message;
    return true;
  }
  if (res.code === 'validation_failed') {
    let any = false;
    for (const [field, selector] of Object.entries(fieldMap)) {
      if (res.details?.[field]) {
        container.querySelector(selector).textContent = res.details[field];
        any = true;
      }
    }
    if (any) return true;
  }
  const fallback = container.querySelector(fallbackSelector);
  if (fallback) fallback.textContent = res.message;
  return true;
}

/** Clear every `.error-msg` element under `container`. */
export function clearErrors(container) {
  container.querySelectorAll('.error-msg').forEach(el => { el.textContent = ''; });
}
