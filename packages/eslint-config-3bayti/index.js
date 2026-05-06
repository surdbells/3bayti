// Shared ESLint flat config for the 3bayti monorepo.
// Apps and packages import this and add their own rules on top.

import js from '@eslint/js';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  // Ignore generated and dependency directories
  {
    ignores: [
      '**/node_modules/**',
      '**/dist/**',
      '**/build/**',
      '**/.angular/**',
      '**/.turbo/**',
      '**/.wrangler/**',
      '**/coverage/**',
      '**/*.generated.ts',
      '**/generated.ts'
    ]
  },

  // JavaScript baseline
  js.configs.recommended,

  // TypeScript baseline (strict)
  ...tseslint.configs.strict,
  ...tseslint.configs.stylistic,

  // 3bayti house rules
  {
    rules: {
      // Allow console.* in dev/scripts but flag everywhere else.
      // App-specific configs override this if they want stricter.
      'no-console': ['warn', { allow: ['warn', 'error', 'info'] }],

      // We use signals + RxJS heavily; allow `any` only when explicitly
      // declared (not implicit).
      '@typescript-eslint/no-explicit-any': 'warn',

      // Force consistent style for type-only imports.
      '@typescript-eslint/consistent-type-imports': [
        'error',
        { prefer: 'type-imports', fixStyle: 'inline-type-imports' }
      ],

      // Forbid `// @ts-ignore`. If you need to escape, use `@ts-expect-error`
      // (which fails to compile if the error goes away — self-cleaning debt).
      '@typescript-eslint/ban-ts-comment': [
        'error',
        {
          'ts-expect-error': 'allow-with-description',
          'ts-ignore': true,
          'ts-nocheck': true,
          'ts-check': false
        }
      ],

      // Unused vars: error, but allow leading underscore as escape hatch.
      '@typescript-eslint/no-unused-vars': [
        'error',
        {
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_'
        }
      ]
    }
  },

  // Test files: relaxed
  {
    files: ['**/*.spec.ts', '**/*.test.ts', '**/tests/**/*.ts'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
      'no-console': 'off'
    }
  }
);
