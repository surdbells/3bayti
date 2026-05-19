import { InjectionToken } from '@angular/core';

/**
 * Base URL of the Angular SSR BFF auth proxy.
 *
 * In development + production this is a relative path ('/auth-proxy')
 * so the browser sends the request to the same origin (no CORS), and
 * the Angular SSR Express handler intercepts it and forwards to the
 * actual API.
 *
 * Overridable for tests (e.g. point at a mock server) and for the
 * rare staging configuration where the BFF runs on a separate host.
 *
 * NO trailing slash. Routes append `/login`, `/refresh`, etc.
 */
export const AUTH_PROXY_BASE = new InjectionToken<string>('AUTH_PROXY_BASE', {
  providedIn: 'root',
  factory: () => '/auth-proxy',
});

/**
 * How far in advance of the access token's expiry to fire the
 * pre-emptive refresh.
 *
 * Defaults to 60s. The access token TTL is 15 minutes, so this fires
 * once every ~14 minutes for an active session. Reducing this risks
 * the token expiring before the refresh completes; increasing it
 * burns more BFF calls than necessary.
 *
 * Overridable for tests (e.g. short-circuit to fire immediately).
 */
export const AUTH_REFRESH_LEAD_TIME_MS = new InjectionToken<number>(
  'AUTH_REFRESH_LEAD_TIME_MS',
  {
    providedIn: 'root',
    factory: () => 60_000,
  },
);

/**
 * Feature flag controlling whether the header surfaces auth CTAs
 * (Sign in / Register).
 *
 * Per Q-FeatureFlag = C (Y.1 plan): routes are always live (staging-only
 * apps/web), but the header CTAs stay hidden until Y.2 ships
 * cart/checkout so users don't sign up only to find a dead end.
 *
 * Default: false. Flip to true in Y.2-close.
 */
export const FEATURE_AUTH_HEADER_CTA = new InjectionToken<boolean>(
  'FEATURE_AUTH_HEADER_CTA',
  {
    providedIn: 'root',
    factory: () => false,
  },
);
