/**
 * AUTO-GENERATED — do not edit by hand.
 *
 * Regenerate with: pnpm --filter @3bayti/api-contracts codegen
 *
 * This is a placeholder version. The real generated.ts will contain
 * the full TypeScript type tree for every endpoint. The pipeline is:
 *
 *   apps/api/src/**.php (swagger-php attributes)
 *           ↓ composer openapi:generate
 *   packages/api-contracts/openapi.yaml
 *           ↓ pnpm codegen (openapi-typescript)
 *   packages/api-contracts/src/generated.ts (this file)
 *           ↓ tsc
 *   apps/web, apps/mobile, apps/portal consume types
 */

/* eslint-disable @typescript-eslint/no-empty-object-type */

export interface paths {
  '/v3/health': {
    get: {
      responses: {
        200: {
          content: {
            'application/json': components['schemas']['HealthResponse'];
          };
        };
      };
    };
  };
}

export interface components {
  schemas: {
    HealthResponse: {
      status: 'ok';
      service: string;
      version: string;
      /** ISO-8601 UTC timestamp */
      timestamp: string;
    };
  };
}

export type operations = {};
