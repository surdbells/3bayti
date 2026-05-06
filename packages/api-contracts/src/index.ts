/**
 * @3bayti/api-contracts
 *
 * Generated TypeScript types from openapi.yaml. The contract between
 * `apps/api` and the three frontends.
 *
 * Workflow:
 *   1. Backend developer adds / changes endpoints in `apps/api/`.
 *      swagger-php attributes describe request/response shapes.
 *   2. CI runs `composer openapi:generate` → updates `openapi.yaml`.
 *   3. CI runs `pnpm codegen` → updates `src/generated.ts`.
 *   4. Frontend code imports types from `@3bayti/api-contracts`:
 *        import type { paths } from '@3bayti/api-contracts';
 *        type LoginReq = paths['/v3/auth/login']['post']['requestBody']['content']['application/json'];
 *
 * If the backend contract changes, frontend TypeScript compilation fails
 * until updated. Drift is caught at compile time.
 */

export type * from './generated.js';
