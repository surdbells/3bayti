# @3bayti/shared-ui Storybook — Authoring Guide

**Phase:** M3.2.0-E
**Framework:** Storybook 8.4 + HTML + Vite 5
**Story location:** `src/**/*.stories.ts`

## Why HTML+Vite (not Angular)

`@3bayti/shared-ui` is intentionally framework-agnostic so it can be
consumed by:

- **apps/web** (Angular 21) — wraps components in Angular adapters
- **apps/portal** (Angular 19) — same pattern
- **Future surfaces** — anything that speaks vanilla DOM

If shared-ui depended on Angular directly, every consuming app would
be locked to the same Angular version. By keeping components as pure
TypeScript + DOM helpers + design tokens, each app handles its own
framework integration.

Storybook stories use plain HTML, mirroring how a consuming app would
ultimately render the component output.

## Running locally

```bash
# Dev server with hot reload (port 6006)
pnpm --filter @3bayti/shared-ui storybook

# Static build (CI + Chromatic)
pnpm --filter @3bayti/shared-ui build-storybook
```

Visit http://localhost:6006 once the dev server is ready.

## Story conventions

### One file per component, multiple stories per file

```typescript
// src/components/Button.stories.ts
import type { Meta, StoryObj } from '@storybook/html';

interface ButtonArgs {
  label: string;
  variant: 'primary' | 'secondary' | 'tertiary';
  disabled: boolean;
  onClick: () => void;
}

const meta: Meta<ButtonArgs> = {
  title: 'Components/Button',
  argTypes: {
    label: { control: 'text' },
    variant: {
      control: 'select',
      options: ['primary', 'secondary', 'tertiary'],
    },
    disabled: { control: 'boolean' },
    onClick: { action: 'clicked' },
  },
  args: {
    label: 'Click me',
    variant: 'primary',
    disabled: false,
  },
};
export default meta;

type Story = StoryObj<ButtonArgs>;

export const Primary: Story = {
  args: { variant: 'primary' },
};

export const Secondary: Story = {
  args: { variant: 'secondary' },
};

export const Disabled: Story = {
  args: { disabled: true },
};

export const LongLabel: Story = {
  args: { label: 'A very long button label that may wrap onto multiple lines' },
};
```

### Story naming

- `Default` — typical use case
- `<Variant>` — alternate variants (Primary, Secondary, Tertiary)
- `<State>` — different states (Disabled, Loading, Error)
- `<EdgeCase>` — boundary conditions (LongLabel, EmptyData, MaxItems)

### args + argTypes

- `args` — default prop values
- `argTypes` — Storybook controls UI definition

Use `select`, `radio`, `boolean`, `text`, `number`, `color` controls for
common prop types.

### Actions

For event handlers, use `{ action: 'eventName' }`. Storybook logs the
event in the Actions panel.

## Decorators (wrapping every story)

For cross-cutting context like dark mode or RTL, use decorators in
`.storybook/preview.ts`:

```typescript
// Already configured: viewport, backgrounds, a11y
// To add RTL toggle, e.g.:
import type { Decorator } from '@storybook/html';

const rtlDecorator: Decorator = (story, ctx) => {
  const wrapper = document.createElement('div');
  wrapper.dir = ctx.globals['direction'] ?? 'ltr';
  wrapper.appendChild(story() as Node);
  return wrapper;
};

export default {
  decorators: [rtlDecorator],
  globalTypes: {
    direction: {
      name: 'Direction',
      defaultValue: 'ltr',
      toolbar: {
        icon: 'transfer',
        items: [
          { value: 'ltr', title: 'LTR (English)' },
          { value: 'rtl', title: 'RTL (Arabic)' },
        ],
      },
    },
  },
};
```

(Not pre-installed; add when RTL components ship in M3.2.Y.)

## Accessibility

Every story is automatically scanned by `@storybook/addon-a11y` using
the same WCAG 2.1 AA tags as `apps/web/e2e/utils/a11y.ts`. Click the
**Accessibility** tab to see violations for the current story.

Story-level a11y is **first-line defense** during development. The
final gate is `expectNoA11yViolations` in Playwright e2e tests on the
real consuming app pages.

## Viewports

The viewport toolbar offers 3 sizes matching `responsive.spec.ts`:

- Mobile (375×667)
- Tablet (768×1024)
- Desktop (1280×800)

Switch via the viewport icon in the toolbar.

## Adding a new component

1. Create the component source: `src/components/MyComponent.ts`

   ```typescript
   export interface MyComponentProps {
     label: string;
   }

   export function MyComponent(props: MyComponentProps): HTMLElement {
     const el = document.createElement('div');
     el.textContent = props.label;
     el.className = 'my-component';
     return el;
   }
   ```

2. Create the story: `src/components/MyComponent.stories.ts`

   ```typescript
   import type { Meta, StoryObj } from '@storybook/html';
   import { MyComponent, type MyComponentProps } from './MyComponent';

   const meta: Meta<MyComponentProps> = {
     title: 'Components/MyComponent',
     render: (args) => MyComponent(args),
   };
   export default meta;

   export const Default: StoryObj<MyComponentProps> = {
     args: { label: 'Hello' },
   };
   ```

3. Export from `src/index.ts`:

   ```typescript
   export { MyComponent, type MyComponentProps } from './components/MyComponent';
   ```

4. Run Storybook to verify:

   ```bash
   pnpm --filter @3bayti/shared-ui storybook
   ```

5. Add component-level Playwright tests in `apps/web/e2e/` when the
   component is integrated into a real page.

## CI behaviour

Storybook builds in CI via `.github/workflows/web.yml` (added in
M3.2.0-F). The static output is consumed by Chromatic for component-
level visual regression alongside the apps/web page-level snapshots.

## Telemetry

Disabled in `.storybook/main.ts` (`core.disableTelemetry: true`). No
prompts in CI, no anonymous data leaks.

## Files

```
packages/shared-ui/
├── .storybook/
│   ├── main.ts          Framework + addons + telemetry config
│   └── preview.ts       Global params (viewports, backgrounds, a11y)
├── src/
│   ├── components/      Components + their .stories.ts files
│   └── index.ts         Public API exports
├── storybook-static/    Build output (gitignored)
├── .gitignore           storybook-static + .cache
├── package.json         storybook + build-storybook scripts
└── STORYBOOK.md         This guide
```
