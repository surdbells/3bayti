import { ErrorHandler, Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';

/**
 * Browser Sentry error reporting — lazily loaded.
 *
 * The @sentry/browser SDK (~85 KB) is dynamically imported so it is only
 * fetched and parsed when SENTRY_DSN is actually configured. When the DSN
 * is unset (dev, test, unconfigured deploys) nothing is shipped to the
 * client beyond this tiny module, and nothing phones home.
 *
 * Scope: error reporting only — no performance/tracing integration, to
 * keep things lean. The DSN is a public client key; shipping it in the
 * browser is expected and safe.
 */

type SentryModule = typeof import('@sentry/browser');

/* Holds the loaded SDK once initSentry() resolves; null until then (and
   forever when no DSN is configured). */
let sentry: SentryModule | null = null;

/**
 * Kick off Sentry initialisation. No-op without a DSN. Called from
 * main.ts before bootstrap; the dynamic import is fire-and-forget so it
 * never delays the app from starting. Errors thrown in the brief window
 * before the SDK finishes loading are still logged to the console by
 * SentryErrorHandler, just not reported.
 */
export function initSentry(): void {
  const dsn = environment.SENTRY_DSN;
  if (!dsn) return;

  void import('@sentry/browser').then((mod) => {
    sentry = mod;
    mod.init({
      dsn,
      environment: environment.SITE_URL.includes('staging') ? 'staging' : 'production',
      /* Drop non-actionable browser noise that would otherwise bury real
         issues. */
      ignoreErrors: [
        'ResizeObserver loop limit exceeded',
        'ResizeObserver loop completed with undelivered notifications.',
      ],
    });
  });
}

/**
 * Angular ErrorHandler that forwards uncaught errors to Sentry (once the
 * SDK has loaded) while preserving the default console logging.
 * Registered only when SENTRY_DSN is configured (see provideMonitoring()).
 */
@Injectable()
export class SentryErrorHandler implements ErrorHandler {
  handleError(error: unknown): void {
    sentry?.captureException(error);
    /* Keep the browser console useful for local debugging. */
    console.error(error);
  }
}
