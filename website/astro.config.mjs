// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import { fileURLToPath } from 'node:url';
import { loadEnv } from 'vite';
import { remarkDocsLinks } from './remark-docs-links.mjs';
import { OG_IMAGE_URL } from './src/chrome-markers.mjs';

const docsDir = fileURLToPath(new URL('../docs', import.meta.url));

// The docs are served under /docs on the site host. remarkDocsLinks rewrites
// Markdown links to absolute routes and Astro does not touch those, so it has
// to prefix the base itself.
const base = '/docs';

// astro.config runs in Node, where .env is not loaded for us. Absent a token the
// widget is simply not injected — see .env.example.
const env = loadEnv(process.env.NODE_ENV ?? 'development', process.cwd(), '');
const widgetHost = env.PUBLIC_SITE_REVIEW_HOST ?? 'https://loupe.dev.localhost';
// Dev server only. The project id lives in a gitignored .env so a deploy would
// not have one anyway. This makes it impossible rather than merely unlikely: no
// build output can carry the widget, whatever the environment holds.
const isDevServer = process.argv.includes('dev');
const siteReviewWidget = isDevServer && env.PUBLIC_SITE_REVIEW_PROJECT
  ? [{
      tag: 'script',
      attrs: {
        src: `${widgetHost}/live-review/widget.js`,
        'data-project': env.PUBLIC_SITE_REVIEW_PROJECT,
      },
    }]
  : [];

export default defineConfig({
  site: 'https://loupe.ac',
  base,
  redirects: {
    '/using/site-review': '/using/live-review',
  },
  markdown: {
    remarkPlugins: [[remarkDocsLinks, { docsDir, base }]],
  },
  integrations: [
    starlight({
      title: 'Loupe',
      // Starlight restores each group's collapsed state from sessionStorage and
      // applies it blindly, so a group you once collapsed stays shut even when
      // the page you are on lives inside it. Re-open the ancestors of the
      // current page after that restore has run.
      head: [
        {
          tag: 'script',
          content:
            "addEventListener('DOMContentLoaded',()=>{let e=document.querySelector('#starlight__sidebar [aria-current=\"page\"]');while(e=e?.closest('details'))e.open=!0,e=e.parentElement})",
        },
        { tag: 'meta', attrs: { property: 'og:image', content: OG_IMAGE_URL } },
        { tag: 'script', attrs: { src: '/site/docs.js', defer: true } },
        ...siteReviewWidget,
      ],
      customCss: ['./src/styles/theme.css'],
      components: {
        Header: './src/components/SiteHeader.astro',
        MobileMenuFooter: './src/components/SiteMobileMenuFooter.astro',
        PageFrame: './src/components/SitePageFrame.astro',
        Sidebar: './src/components/SiteSidebar.astro',
      },
      description: 'A document- and live-review tool for humans working with AI agents.',
      editLink: {
        baseUrl: 'https://github.com/loupe-ac/loupe/edit/main/docs/',
      },
      sidebar: [
        { label: 'Introduction', slug: 'index' },
        {
          label: 'Getting started',
          collapsed: true,
          items: [
            { label: 'First steps', slug: 'getting-started' },
            { slug: 'getting-started/cli' },
            { slug: 'getting-started/run-the-bridge' },
            { slug: 'getting-started/agent-github-account' },
          ],
        },
        {
          label: 'Using Loupe',
          collapsed: true,
          items: [
            { slug: 'using/projects' },
            { slug: 'using/workshop' },
            { slug: 'using/documents' },
            { slug: 'using/mcp' },
            { slug: 'using/connected-apps' },
            { slug: 'using/openrouter' },
            { slug: 'using/live-review' },
            { slug: 'using/board' },
            { slug: 'using/workflows' },
            { slug: 'using/inbox' },
            { slug: 'using/notifications' },
            { slug: 'using/artifacts' },
            {
              label: 'Activity',
              items: [
                { label: 'Runs', slug: 'using/worker-runs' },
                { label: 'Events', slug: 'using/activity' },
              ],
            },
            {
              label: 'Analytics',
              items: [
                { label: 'Metrics and reports', slug: 'using/analytics' },
                { label: 'Experiments', slug: 'using/experiments' },
              ],
            },
            { slug: 'using/search' },
            { slug: 'using/admin' },
            { slug: 'using/data-exports' },
            { slug: 'using/billing' },
          ],
        },
        {
          label: 'Extending Loupe',
          collapsed: true,
          items: [
            { slug: 'extending/reverse-proxy' },
            { slug: 'extending/mercure' },
            { slug: 'extending/object-storage' },
            { slug: 'extending/oauth' },
            { slug: 'extending/billing' },
            { slug: 'extending/cli-bridge' },
            { slug: 'extending/repository-profile' },
            { slug: 'extending/bridge-hooks' },
            { slug: 'extending/forge-webhooks' },
            { slug: 'extending/artifact-tools' },
          ],
        },
        {
          label: 'Operating',
          collapsed: true,
          items: [
            {
              label: 'Installing',
              items: [
                { label: 'Choosing a path', slug: 'operating/install' },
                { slug: 'operating/install/demo' },
                { slug: 'operating/install/from-source' },
                { slug: 'operating/install/docker-compose' },
                { slug: 'operating/install/digitalocean' },
              ],
            },
            { slug: 'operating/first-run' },
            { slug: 'operating/migrations' },
            { slug: 'operating/post-deploy-checks' },
            { slug: 'operating/sentry' },
            { slug: 'operating/artifact-content-domain' },
            { slug: 'operating/failed-messages' },
            { slug: 'operating/web-push' },
            { slug: 'operating/recovering' },
            { slug: 'operating/backups' },
            { slug: 'operating/restoring' },
            { slug: 'operating/rotating-encryption-key' },
            { slug: 'operating/rotating-secrets' },
          ],
        },
        {
          label: 'Reference',
          collapsed: true,
          items: [
            { slug: 'reference/environment' },
            { slug: 'reference/commands' },
            { slug: 'reference/cli' },
            { slug: 'reference/worker-runs' },
            { slug: 'reference/bridge-heartbeat' },
          ],
        },
        {
          label: 'Contributing',
          collapsed: true,
          items: [
            { label: 'Overview', slug: 'contributing' },
            { slug: 'contributing/development' },
            { slug: 'contributing/architectural-priorities' },
            { slug: 'contributing/design-system' },
            { slug: 'contributing/worktrees' },
            { slug: 'contributing/lifecycle' },
            { slug: 'contributing/instruction-audit' },
          ],
        },
        {
          label: 'Decision records',
          collapsed: true,
          items: [
            { label: 'Overview', slug: 'adr' },
            { slug: 'adr/0001-app-over-worker' },
            { slug: 'adr/0002-generic-stage-skills' },
            { slug: 'adr/0003-replace-dont-keep' },
            { slug: 'adr/0004-workflow-concerns-stay-in-workflow' },
            { slug: 'adr/0005-live-ui' },
          ],
        },
        { label: 'What runs in production', slug: 'architecture' },
        { label: 'Troubleshooting', slug: 'troubleshooting' },
        { label: 'Known gaps', slug: 'known-gaps' },
        { label: 'Changelog', slug: 'changelog' },
        { label: 'Security policy', slug: 'security' },
      ],
    }),
  ],
});
