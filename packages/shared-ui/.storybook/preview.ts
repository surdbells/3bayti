import type { Preview } from '@storybook/html';

/**
 * Storybook preview configuration for @3bayti/shared-ui.
 *
 * M3.2.0-E — global parameters applied to every story.
 *
 * Globals:
 *   - viewports: mobile (375×667), tablet (768×1024), desktop (1280×800)
 *     matching responsive.spec.ts in apps/web/e2e
 *   - backgrounds: light + dark + brand
 *   - a11y addon: WCAG 2.1 AA enabled by default
 */
const preview: Preview = {
  parameters: {
    controls: {
      matchers: {
        color: /(background|color)$/i,
        date: /Date$/i,
      },
    },

    backgrounds: {
      default: 'light',
      values: [
        { name: 'light', value: '#ffffff' },
        { name: 'dark', value: '#1a1a1a' },
        { name: 'brand', value: '#B9935A' },
      ],
    },

    viewport: {
      viewports: {
        mobile: {
          name: 'Mobile (375×667)',
          styles: { width: '375px', height: '667px' },
        },
        tablet: {
          name: 'Tablet (768×1024)',
          styles: { width: '768px', height: '1024px' },
        },
        desktop: {
          name: 'Desktop (1280×800)',
          styles: { width: '1280px', height: '800px' },
        },
      },
    },

    a11y: {
      // WCAG 2.1 AA — matches apps/web/e2e/utils/a11y.ts gate
      config: {
        rules: [
          { id: 'color-contrast', enabled: true },
        ],
      },
      options: {
        runOnly: {
          type: 'tag',
          values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
      },
    },
  },

  tags: ['autodocs'],
};

export default preview;
