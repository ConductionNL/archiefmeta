// @ts-check

/**
 * archiefmeta documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens, theme
 * swizzles for Navbar and Footer, copyright). Site-specific overrides
 * (English only, docs at the site root, no blog) are passed through
 * createConfig() opts.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

const config = createConfig({
  title: 'archiefmeta',
  tagline: 'Read-only archival metadata from the storage layer in Nextcloud Files',
  url: 'https://archiefmeta.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'archiefmeta',

  /* English only. Dutch project pages mark their body with lang="nl"
     instead of living in a second locale. */
  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
    localeConfigs: {
      en: { label: 'English' },
    },
  },

  /* The docs source is the root of docs/ itself, so docs.path is './'.
     That makes plugin-content-docs scan every file in docs/, including
     src/ and node_modules/, hence the exclude list. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: './',
          routeBasePath: '/',
          exclude: ['**/node_modules/**', 'src/**', 'build/**'],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://github.com/ConductionNL/archiefmeta/tree/development/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
  ],

  /* Object.assign in createConfig is shallow, so items: replaces the
     brand navbar items wholesale. */
  navbar: {
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'docsSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        href: 'https://github.com/ConductionNL/archiefmeta',
        label: 'GitHub',
        position: 'right',
      },
    ],
  },

  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  minigames: false,
});

config.onBrokenAnchors = 'warn';
/* .md pages are plain CommonMark, so raw logs with < and { render as text.
   Pages that need JSX, such as the Dutch pages with lang="nl", are .mdx. */
config.markdown = { format: 'detect' };

module.exports = config;
