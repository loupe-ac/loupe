// Checks the built docs in dist/ (or the folder in argument 1). A page fails
// when it has a next page and no pagination-links element, or when it lacks
// one of the three chrome markers. The source pages in docs/ fail when a relative
// link names a .md file that does not exist, because the build keeps such a link. `--built-only` skips
// that check for the image build, whose context lacks the files outside docs/. CI runs it.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { FOOTER_MARKER, MENU_MARKER, NAV_MARKER } from './src/chrome-markers.mjs';

const args = process.argv.slice(2);
const folder = args.find((arg) => !arg.startsWith('--'));
const root = path.resolve(folder ?? fileURLToPath(new URL('./dist', import.meta.url)));

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
  if (!html.includes(MENU_MARKER)) problems.push(`${name}: phone menu marker missing`);
  if (html.includes('rel="next"') && !html.includes('pagination-links')) {
    problems.push(`${name}: has a next page but no pagination-links element`);
  }
}

if (checked === 0) problems.push(`no docs page found under ${root}`);

const docsRoot = fileURLToPath(new URL('../docs', import.meta.url));
const walkSources = (directory) => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const full = path.join(directory, entry.name);
    if (entry.isDirectory()) walkSources(full);
    else if (entry.name.endsWith('.md')) checkLinks(full);
  }
};
const checkLinks = (file) => {
  const source = fs.readFileSync(file, 'utf8').replace(/```[\s\S]*?```/g, '');
  for (const [, target] of source.matchAll(/\]\(([^)\s#]+\.md)(?:#[^)\s]*)?\)/g)) {
    if (/^[a-z]+:/i.test(target) || target.startsWith('/')) continue;
    if (!fs.existsSync(path.resolve(path.dirname(file), target))) {
      problems.push(`${path.relative(docsRoot, file)}: broken link to ${target}`);
    }
  }
};
if (!args.includes('--built-only')) walkSources(docsRoot);
if (problems.length > 0) {
  console.error(problems.join('\n'));
  process.exit(1);
}
console.log(`${checked} docs pages checked.`);
