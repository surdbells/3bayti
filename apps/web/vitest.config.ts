/**
 * Vitest configuration for apps/web.
 *
 * Why this file exists
 * --------------------
 * Angular 21's @angular/build:unit-test generates a vitest config at
 * build time in .angular/cache. Running `npx vitest run` directly uses
 * Vitest's default discovery, which:
 *   1. Picks up e2e/*.spec.ts (Playwright files) and crashes on
 *      `test.describe()`, Playwright's test is not Vitest's test.
 *   2. Has no Angular TestBed setup, so tests that call
 *      TestBed.configureTestingModule() throw "initTestEnvironment first".
 *   3. Has globals:false, so specs that use bare describe/it/expect
 *      (without importing from vitest) throw ReferenceError.
 *
 * This config fixes all three.
 *
 * CI note
 * -------
 * CI runs `pnpm --filter @3bayti/web test:e2e` (Playwright), not vitest.
 * This config is for local development quality checks:
 *   npx vitest run          # all unit tests
 *   npx vitest watch        # watch mode
 */

import { defineConfig, type Plugin } from 'vitest/config';

function angularStyleStub(): Plugin {
  return {
    name: 'angular-style-stub',
    transform(_code: string, id: string) {
      if (id.endsWith('.scss') || id.endsWith('.css')) {
        return { code: 'export default ""', map: null };
      }
      return null;
    },
    load(id: string) {
      if (id.endsWith('.scss') || id.endsWith('.css')) {
        return 'export default ""';
      }
      return null;
    },
  };
}

/**
 * Plugin that uses TypeScript's own compiler to transpile ALL TypeScript files.
 * This is required because `esbuild: false` disables esbuild's TS handling,
 * and we need emitDecoratorMetadata for spec-defined @Component classes.
 *
 * For spec files: emitDecoratorMetadata=true so Angular JIT can resolve
 *   constructor parameter types (e.g. `constructor(fb: FormBuilder)`).
 * For all other files: standard transpilation without decorator metadata
 *   (app code uses the modern `inject()` API which doesn't need it).
 */
function angularSpecDecoratorMetadata(): Plugin {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const ts = require('typescript') as typeof import('typescript');

  const baseOptions: import('typescript').CompilerOptions = {
    target: ts.ScriptTarget.ES2022,
    module: ts.ModuleKind.ES2022,
    moduleResolution: ts.ModuleResolutionKind.Bundler,
    experimentalDecorators: true,
    useDefineForClassFields: false,
    sourceMap: true,
    inlineSources: true,
    skipLibCheck: true,
  };

  // Spec files: emitDecoratorMetadata tells tsc to emit __metadata("design:paramtypes", ...)
  // calls for constructors. Angular's JIT reads these via Reflect.getMetadata()
  // (requires reflect-metadata polyfill, imported in vitest.setup.ts).
  const specOptions: import('typescript').CompilerOptions = {
    ...baseOptions,
    emitDecoratorMetadata: true,
  };

  return {
    name: 'angular-spec-decorator-metadata',
    enforce: 'pre',
    transform(code: string, id: string) {
      if (!id.endsWith('.ts') && !id.endsWith('.tsx')) return null;
      // Skip node_modules, they're already compiled
      if (id.includes('node_modules')) return null;

      const isSpec = id.endsWith('.spec.ts');
      const options = isSpec ? specOptions : baseOptions;

      const result = ts.transpileModule(code, {
        compilerOptions: options,
        fileName: id,
        reportDiagnostics: false,
      });

      return {
        code: result.outputText,
        map: result.sourceMapText ?? null,
      };
    },
  };
}

export default defineConfig({
  plugins: [
    // Style stubs must run before Angular compiler tries to load them.
    angularStyleStub(),
    // Decorator metadata for spec-defined @Component classes with constructor DI.
    angularSpecDecoratorMetadata(),
  ],

  // Disable esbuild for spec files so our tsc plugin output isn't overwritten.
  // esbuild strips emitDecoratorMetadata which Angular's JIT needs.
  // App code (non-spec) still uses the fast esbuild path.
  esbuild: false,

  test: {
    // Globals: true lets spec files use describe/it/expect without
    // importing them from vitest.  Some files do import explicitly;
    // both styles work with globals enabled.
    globals: true,

    // jsdom gives browser-like globals (window, document, localStorage)
    // required by Angular's platform-browser and any DOM-touching code.
    environment: 'jsdom',

    // Initialise Angular's TestBed environment once per worker.
    // Must run before any spec file that calls TestBed.configureTestingModule.
    setupFiles: ['./vitest.setup.ts'],

    // --- Include / exclude ---------------------------------------------------

    // Only pick up spec files inside src/, never touch e2e/ (those are
    // Playwright files that use a different test() API and fail in Vitest).
    include: ['src/**/*.spec.ts'],

    // Exclude Playwright e2e specs explicitly as a belt-and-suspenders guard.
    exclude: [
      'e2e/**',
      '**/node_modules/**',
      '**/dist/**',
      '**/.angular/**',
    ],

    // --- Performance ---------------------------------------------------------

    // singleThread: true keeps Angular DI and TestBed state predictable
    // across tests that call resetTestingModule().
    singleThread: true,
  },
});
