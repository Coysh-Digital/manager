import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Manager',
  description: 'A control plane for a fleet of Craft CMS installations. Watches, does not touch.',
  lang: 'en-GB',
  cleanUrls: true,
  lastUpdated: true,

  head: [
    // No analytics and no third-party fonts. A documentation site for a security product should not
    // be telling anybody else who is reading about it.
  ],

  themeConfig: {
    nav: [
      { text: 'Get started', link: '/getting-started' },
      { text: 'Features', link: '/monitoring' },
      { text: 'Backups', link: '/backups' },
      { text: 'The Craft plugin', link: '/craft-plugin' },
      {
        text: 'Reference',
        items: [
          { text: 'Environment variables', link: '/env' },
          { text: 'Upgrading', link: '/upgrade' },
          { text: 'Rolling back', link: '/rollback' },
          { text: 'Verifying a release', link: '/verify' },
        ],
      },
    ],

    sidebar: [
      {
        text: 'Setting it up',
        items: [
          { text: 'Getting started', link: '/getting-started' },
          { text: 'Installing', link: '/install' },
          { text: 'Behind a reverse proxy', link: '/reverse-proxy' },
          { text: 'Environment variables', link: '/env' },
        ],
      },
      {
        text: 'Connecting your sites',
        items: [
          { text: 'The Craft plugin', link: '/craft-plugin' },
          { text: 'Pairing a site', link: '/pairing' },
          { text: 'Permissions', link: '/capabilities' },
        ],
      },
      {
        text: 'Using it',
        items: [
          { text: 'What it watches', link: '/monitoring' },
          { text: 'Backups', link: '/backups' },
          { text: 'Recovery keys', link: '/recovery-keys' },
          { text: 'Restoring a backup', link: '/restoring' },
        ],
      },
      {
        text: 'Running it',
        items: [
          { text: 'What it does and does not do', link: '/what-it-does' },
          { text: 'Security', link: '/security' },
          { text: 'Platform backups', link: '/backup' },
          { text: 'Upgrading', link: '/upgrade' },
          { text: 'Rolling back', link: '/rollback' },
          { text: 'Verifying a release', link: '/verify' },
          { text: 'Troubleshooting', link: '/troubleshooting' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/Coysh-Digital/manager' },
    ],

    editLink: {
      pattern: 'https://github.com/Coysh-Digital/manager/edit/main/docs/:path',
      text: 'Suggest a change to this page',
    },

    search: { provider: 'local' },

    footer: {
      message: 'Source-available. Running it yourself is free.',
      copyright: 'Coysh Digital',
    },
  },
})
