import type { StorybookConfig } from '@storybook/html-vite';

/**
 * Storybook configuration for @3bayti/shared-ui.
 *
 * M3.2.0-E — component library scaffold for the M3.2 web build.
 *
 * Framework: HTML + Vite (NOT Angular)
 *   Rationale: shared-ui is "framework-agnostic where possible" per
 *   the package's own README. apps/web wraps components in Angular
 *   adapters; portal/legacy can use raw HTML. Storybook stories use
 *   plain HTML, which works for both consumers without forcing
 *   Angular into shared-ui's dependency tree.
 *
 * Addons:
 *   - addon-essentials: controls, viewport, backgrounds, actions
 *   - addon-a11y: in-browser axe-core panel for each story
 *
 * Story discovery: src/** matching .stories.@(ts|html).
 *
 * To run:
 *   pnpm --filter @3bayti/shared-ui storybook        # dev mode
 *   pnpm --filter @3bayti/shared-ui build-storybook  # static build
 */
const config: StorybookConfig = {
  framework: '@storybook/html-vite',

  stories: [
    '../src/**/*.stories.@(ts|html|mdx)',
  ],

  addons: [
    '@storybook/addon-essentials',
    '@storybook/addon-a11y',
  ],

  docs: {
    autodocs: 'tag',
  },

  staticDirs: [],

  // We don't use TypeScript story types directly — html-vite handles
  // the type checking inline. This is left as default.
  typescript: {
    check: false,
  },

  // Disable telemetry — never prompt in CI; respect privacy.
  core: {
    disableTelemetry: true,
  },
};

export default config;
