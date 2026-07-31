import { defineConfig } from 'vitepress'

/**
 * The documentation site.
 *
 * The markdown lives here, in the public AGPL repository, next to the code it describes. That is
 * the point: a self-hoster reading the source finds the docs in the same clone, a change to
 * behaviour and a change to its documentation land in the same commit, and anyone can send a
 * correction as a pull request.
 *
 * It is published at managerforcraft.com/docs rather than on a docs subdomain, so search authority
 * stays on one hostname and a reader mid-evaluation never leaves the site. The marketing
 * application serves that path as static files from an nginx location ahead of its own fallback;
 * see cloud/deploy/ploi/README.md in the private cloud repository.
 *
 * Every page stays at the path it already had. `install.md`, `env.md`, `backup.md`, `security.md`,
 * `reverse-proxy.md`, `upgrade.md` and `rollback.md` are referenced from README.md,
 * LICENSE.md, .env.example, compose.yaml, the release workflow and the output of four console
 * commands. Rearranging them into folders for tidier URLs would break every one of those, including
 * messages printed to an operator in the middle of an incident. The sidebar groups them without
 * them having to move.
 */
const SITE = 'https://managerforcraft.com'

export default defineConfig({
  title: 'Manager for Craft',
  description:
    'Documentation for Manager for Craft: connecting a Craft CMS site, what the connector reports, encrypted backups, recovery keys, and self-hosting the control plane.',

  lang: 'en-GB',
  base: '/docs/',

  // Drops the .html, which matters because every link from the marketing site is written without
  // one. The nginx location block serves index.html for a directory and $uri.html for a page.
  cleanUrls: true,

  // A broken cross-reference should fail the build rather than ship. The docs are large enough that
  // nobody is going to click every link before a release.
  ignoreDeadLinks: false,

  lastUpdated: true,

  // The base has to be on the hostname here, not just in `base`. VitePress builds a sitemap entry
  // from hostname plus the page's path relative to the base, so `hostname: SITE` alone publishes
  // https://managerforcraft.com/install for a page that actually lives at /docs/install, and every
  // URL in the sitemap 404s.
  sitemap: { hostname: `${SITE}/docs/` },

  head: [
    ['meta', { name: 'robots', content: 'index, follow, max-image-preview:large' }],
    ['meta', { property: 'og:site_name', content: 'Manager for Craft' }],
    ['meta', { property: 'og:image', content: `${SITE}/og-image.png` }],
    ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ['link', { rel: 'icon', href: '/favicon.svg', type: 'image/svg+xml' }],
    [
      'script',
      { type: 'application/ld+json' },
      JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'TechArticle',
        name: 'Manager for Craft documentation',
        publisher: { '@type': 'Organization', name: 'Coysh Digital', url: SITE },
        isPartOf: { '@type': 'WebSite', url: SITE },
      }),
    ],
  ],

  themeConfig: {
    siteTitle: 'Manager for Craft',

    // Local search: an index built at compile time and shipped with the site. No third-party
    // service, and nothing leaves the reader's browser. It is the only option consistent with a
    // product that self-hosts its own fonts to avoid exactly that.
    search: { provider: 'local' },

    nav: [
      { text: 'Guide', link: '/getting-started', activeMatch: '^/(getting-started|craft-plugin|pairing|capabilities|monitoring)' },
      { text: 'Backups', link: '/backups', activeMatch: '^/(backups|recovery-keys|restoring)' },
      { text: 'Self-hosting', link: '/install', activeMatch: '^/(install|env|reverse-proxy|upgrade|rollback|backup|security)' },
      { text: 'Product', link: `${SITE}/features` },
      { text: 'Pricing', link: `${SITE}/pricing` },
    ],

    sidebar: [
      {
        text: 'Introduction',
        items: [
          { text: 'What Manager is', link: '/' },
          { text: 'What it does, and does not', link: '/what-it-does' },
          { text: 'Getting started', link: '/getting-started' },
        ],
      },
      {
        text: 'Connecting sites',
        items: [
          { text: 'The Craft plugin', link: '/craft-plugin' },
          { text: 'Pairing a site', link: '/pairing' },
          { text: 'Permissions', link: '/capabilities' },
          { text: 'What it watches', link: '/monitoring' },
          { text: 'Troubleshooting', link: '/troubleshooting' },
        ],
      },
      {
        text: 'Backups and recovery',
        items: [
          { text: 'Backups', link: '/backups' },
          { text: 'Recovery keys', link: '/recovery-keys' },
          { text: 'Restoring a backup', link: '/restoring' },
        ],
      },
      {
        text: 'Self-hosting',
        items: [
          { text: 'Installation', link: '/install' },
          { text: 'Environment reference', link: '/env' },
          { text: 'Reverse proxy and TLS', link: '/reverse-proxy' },
          { text: 'Upgrading', link: '/upgrade' },
          { text: 'Rolling back', link: '/rollback' },
          { text: 'Platform backups', link: '/backup' },
          { text: 'Security', link: '/security' },
        ],
      },
    ],

    editLink: {
      pattern: 'https://github.com/Coysh-Digital/manager/edit/main/docs/:path',
      text: 'Suggest a change to this page',
    },

    socialLinks: [{ icon: 'github', link: 'https://github.com/Coysh-Digital/manager' }],

    outline: [2, 3],

    footer: {
      message:
        'AGPL-3.0-or-later. An independent product for Craft CMS, not affiliated with or endorsed by Pixel & Tonic.',
      copyright: 'Coysh Digital',
    },

    lastUpdatedText: 'Last updated',
    darkModeSwitchLabel: 'Theme',
    returnToTopLabel: 'Back to top',
  },
})
