import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Not `new URL()`: jsdom replaces the global URL, and its URL does not resolve file paths.
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * Runs a Magento RequireJS module without RequireJS and returns what its factory returns.
 *
 * @param {string} path Module file, relative to the repository root.
 * @param {Record<string, unknown>} dependencies Value to inject for each AMD dependency name.
 */
export function loadAmdModule(path, dependencies = {}) {
  const source = readFileSync(resolve(repoRoot, path), 'utf8');
  let exported;
  const define = (names, factory) => {
    const missing = names.filter((name) => !(name in dependencies));
    if (missing.length > 0) {
      throw new Error(`${path} needs a test value for: ${missing.join(', ')}`);
    }
    exported = factory(...names.map((name) => dependencies[name]));
  };
  new Function('define', source)(define);
  return exported;
}
