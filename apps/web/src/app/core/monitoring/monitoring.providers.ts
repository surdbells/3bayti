import {
  EnvironmentProviders,
  ErrorHandler,
  Provider,
  makeEnvironmentProviders,
  provideAppInitializer,
  inject,
} from '@angular/core';
import { environment } from '../../../environments/environment';
import { SentryErrorHandler } from './sentry';
import { AnalyticsService } from './analytics.service';

/**
 * Wire browser monitoring. Both pieces are build-time env-gated and
 * become no-ops when their configuration is absent:
 *
 *   - Sentry error reporting (SENTRY_DSN), swaps in an ErrorHandler that
 *     also reports uncaught errors to Sentry. Sentry.init() itself runs
 *     earlier in main.ts (before bootstrap); this only registers the
 *     handler when a DSN is configured.
 *   - Google Analytics 4 (GA4_MEASUREMENT_ID), an APP_INITIALIZER kicks
 *     off AnalyticsService, which loads gtag.js and tracks SPA page
 *     views. AnalyticsService.init() is itself a no-op without the id.
 */
export function provideMonitoring(): EnvironmentProviders {
  const providers: Provider[] = [];

  if (environment.SENTRY_DSN) {
    providers.push({ provide: ErrorHandler, useClass: SentryErrorHandler });
  }

  return makeEnvironmentProviders([
    ...providers,
    provideAppInitializer(() => {
      inject(AnalyticsService).init();
    }),
  ]);
}
