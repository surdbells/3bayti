#!/usr/bin/env node
/**
 * Route-key codegen.
 *
 * Reads the canonical endpoint registry (packages/api-client/src/feature-flags.ts)
 * and emits a TypeScript union of every valid route key plus a runtime
 * frozen set. Consumed by PortalCrudAdapter so get_v3/post_v3/etc. reject
 * unknown routes at COMPILE time instead of failing at runtime.
 *
 * Run:  node tools/gen-route-keys.mjs
 * Output: src/app/services/v3-route-keys.generated.ts
 *
 * This is intentionally a lightweight slice of "typed API client": it gives
 * route-key safety (the highest-frequency class of typo) without requiring
 * an OpenAPI spec, which the API does not yet publish. Full request/response
 * DTO generation is a separate, larger effort gated on swagger-php
 * annotations landing in the API.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const registryPath = resolve(__dirname, '../../../packages/api-client/src/feature-flags.ts');
const outPath = resolve(__dirname, '../src/app/services/v3-route-keys.generated.ts');

const src = readFileSync(registryPath, 'utf8');

// Match the object keys of ENDPOINT_ROUTING: 'METHOD /path': {
const keyRe = /^\s*'((?:GET|POST|PUT|PATCH|DELETE) [^']+)'\s*:/gm;
const keys = [];
let m;
while ((m = keyRe.exec(src)) !== null) keys.push(m[1]);

const unique = [...new Set(keys)].sort();
if (unique.length === 0) {
  console.error('No route keys found — registry format may have changed.');
  process.exit(1);
}

const union = unique.map((k) => `  | '${k}'`).join('\n');
const setItems = unique.map((k) => `  '${k}',`).join('\n');

const out = `/**
 * AUTO-GENERATED — do not edit by hand.
 * Source: packages/api-client/src/feature-flags.ts (ENDPOINT_ROUTING).
 * Regenerate: node tools/gen-route-keys.mjs
 *
 * ${unique.length} route keys.
 */

/** Every valid v3 route key, as a compile-time-checked union. */
export type V3RouteKey =
${union};

/** Runtime set of valid route keys (frozen). */
export const V3_ROUTE_KEYS: ReadonlySet<V3RouteKey> = new Set([
${setItems}
] as const);

/** Type guard: is an arbitrary string a known route key? */
export function isV3RouteKey(key: string): key is V3RouteKey {
  return (V3_ROUTE_KEYS as ReadonlySet<string>).has(key);
}
`;

writeFileSync(outPath, out);
console.log(`Generated ${unique.length} route keys → ${outPath}`);
