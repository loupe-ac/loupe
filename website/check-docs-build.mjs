// Checks the built docs in dist/ (or the folder in argument 1). A page fails
// when it has a next page and no pagination-links element, or when it lacks
// one of the two chrome markers.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { FOOTER_MARKER, NAV_MARKER } from './src/chrome-markers.mjs';

const root = path.resolve(process.argv[2] ?? fileURLToPath(new URL('./dist', import.meta.url)));

const pages = [];
const walk = (directory) => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const full = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.name.endsWith('.html')) pages.push(full);
  }
};
if (!fs.existsSync(root)) {
  console.error(`No build output at ${root}. Run "npm run build" first.`);
  process.exit(1);
}
walk(root);

const problems = [];
let checked = 0;
for (const page of pages) {
  const html = fs.readFileSync(page, 'utf8');
  // Redirect stubs and the 404 page are not docs pages.
  if (!html.includes('data-pagefind-body')) continue;
  checked++;
  const name = path.relative(root, page);
  if (!html.includes(NAV_MARKER)) problems.push(`${name}: site bar marker missing`);
  if (!html.includes(FOOTER_MARKER)) problems.push(`${name}: site footer marker missing`);
  if (html.includes('rel="next"') && !html.includes('pagination-links')) {
    problems.push(`${name}: has a next page but no pagination-links element`);
  }
}

if (checked === 0) problems.push(`no docs page found under ${root}`);
if (problems.length > 0) {
  console.error(problems.join('\n'));
  process.exit(1);
}
console.log(`${checked} docs pages checked.`);
