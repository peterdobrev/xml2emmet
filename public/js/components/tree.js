function nodeToLines(node, prefix, isLast) {
  const connector = isLast ? '└─ ' : '├─ ';
  let label = node.tag;
  if (node.attrs && Object.keys(node.attrs).length > 0) {
    const attrStr = Object.entries(node.attrs)
      .map(([k, v]) => v ? `${k}="${v}"` : k)
      .join(' ');
    label += ` [${attrStr}]`;
  }
  if (node.text) label += ` {${node.text}}`;

  const lines = [prefix + connector + label];
  const childPrefix = prefix + (isLast ? '   ' : '│  ');
  const children = node.children ?? [];
  children.forEach((child, i) => {
    lines.push(...nodeToLines(child, childPrefix, i === children.length - 1));
  });
  return lines;
}

export function render(container, node) {
  if (!node) { container.textContent = ''; return; }
  const lines = nodeToLines(node, '', true);
  container.textContent = lines.join('\n');
}
