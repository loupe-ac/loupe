import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// docs/ is browsable on GitHub, so its links are ordinary relative paths to
// .md files. Those do not survive the build: a page written at
// docs/known-gaps.md is served from /known-gaps/, one level deeper than the
// file, so every relative link from it would resolve one segment too far.
// Each link is therefore resolved against the file that wrote it and re-emitted
// as a root-relative route. Targets outside docs/ cannot be routes at all and
// become links to the repository instead.
const REPO = 'https://github.com/loupe-ac/loupe';

const routeFor = (relativePath, base) => {
  const withoutExtension = relativePath.replace(/\.md$/, '');
  const segments = withoutExtension.split(path.sep);
  if (segments.at(-1) === 'index') segments.pop();
  const route = segments.join('/').toLowerCase();
  return route === '' ? `${base}/` : `${base}/${route}/`;
};

// Returns the rewritten URL, or null when the link stays as written.
export function rewriteDocsLink(url, from, docsDir, base = '') {
  const prefix = base.replace(/\/$/, '');
  const [target, hash] = splitHash(url);
  if (!target || /^([a-z]+:|\/|#)/i.test(target)) return null;

  const absolute = path.resolve(path.dirname(from), target);
  const relative = path.relative(docsDir, absolute);

  if (relative.startsWith('..')) {
    // blob/ is a file view and 404s on a directory, so ask the filesystem
    // which this is rather than trusting a GitHub redirect.
    const kind = isDirectory(absolute) ? 'tree' : 'blob';
    const fromRoot = path.relative(path.resolve(docsDir, '..'), absolute);
    return `${REPO}/${kind}/main/${fromRoot}${hash}`;
  }
  return target.endsWith('.md') ? routeFor(relative, prefix) + hash : null;
}

export const docsLinks = ({ docsDir, base = '' }) => ({ fileURL }) => {
  if (!fileURL) return null;
  const from = fileURLToPath(fileURL);
  return {
    name: 'docs-links',
    link(node, ctx) {
      const url = rewriteDocsLink(node.url, from, docsDir, base);
      if (url !== null) ctx.setProperty(node, 'url', url);
    },
  };
};

const isDirectory = (target) => {
  try {
    return fs.statSync(target).isDirectory();
  } catch {
    return false;
  }
};

const splitHash = (url) => {
  const index = url.indexOf('#');
  return index === -1 ? [url, ''] : [url.slice(0, index), url.slice(index)];
};
