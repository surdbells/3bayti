import type { Meta, StoryObj } from '@storybook/html';

/**
 * Welcome page for the shared-ui Storybook.
 *
 * M3.2.0-E — bootstrap story demonstrating the authoring pattern.
 * Real components land in M3.2.Y phases when the web build needs
 * them (Button, Modal, FormField, etc. per the package README).
 *
 * This story validates that:
 *   - Storybook builds successfully
 *   - Story discovery works for src/components/*.stories.ts
 *   - The HTML + Vite framework integration is wired correctly
 *   - Addons load (a11y panel, viewport switcher, backgrounds)
 */
const meta: Meta = {
  title: 'Welcome',
  parameters: {
    layout: 'centered',
    docs: {
      description: {
        component: `
The 3bayti shared UI component library.

This Storybook is the development sandbox for cross-app components
consumed by \`apps/web\` and \`apps/portal\` (and selectively by
\`apps/mobile\` via Ionic adapter layers).

## How to use

1. Pick a component from the sidebar
2. Use the **Controls** tab to tweak props
3. Switch viewport via the toolbar (mobile/tablet/desktop)
4. Check the **Accessibility** tab for axe-core findings
5. Use the **Background** toolbar to test light/dark/brand themes

## Components

Real components arrive in M3.2.Y phases. This page is a placeholder.
        `,
      },
    },
  },
};

export default meta;

type Story = StoryObj;

export const Index: Story = {
  render: () => {
    const container = document.createElement('div');
    container.style.cssText = `
      max-width: 600px;
      padding: 48px 32px;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
      color: #2c2c2c;
      line-height: 1.6;
    `;
    container.innerHTML = `
      <h1 style="margin: 0 0 16px; color: #B9935A;">3bayti Shared UI</h1>
      <p style="margin: 0 0 24px;">
        Cross-app component library for the 3bayti monorepo.
      </p>
      <h2 style="margin: 24px 0 12px; font-size: 18px;">Status</h2>
      <p style="margin: 0 0 12px;">
        M3.2.0-E scaffold. Components land in M3.2.Y phases:
      </p>
      <ul style="margin: 0 0 24px; padding-left: 24px;">
        <li>Button (M3.2.Y.1)</li>
        <li>FormField (M3.2.Y.1)</li>
        <li>Modal (M3.2.Y.1)</li>
        <li>PriceDisplay (M3.2.Y.2)</li>
        <li>VendorBadge (M3.2.Y.4)</li>
      </ul>
      <p style="margin: 0; font-size: 14px; color: #666;">
        See docs/plans/m3.2-master-plan.md §2 Stream Y for the
        full component list and phase mapping.
      </p>
    `;
    return container;
  },
};
