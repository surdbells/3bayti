import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { AnalyticsService } from './analytics.service';
import { SentryErrorHandler } from './sentry';
import { environment } from '../../../environments/environment';

/**
 * Monitoring is build-time env-gated. In dev/test the defaults are empty
 * (SENTRY_DSN='' , GA4_MEASUREMENT_ID=''), so both pieces must be inert:
 * GA4 must not inject gtag.js, and the Sentry ErrorHandler must log to the
 * console without depending on the (never-loaded) SDK. These tests lock in
 * that safe default so a future change can't accidentally phone home from
 * an unconfigured build.
 */
describe('monitoring (env-gated off by default)', () => {
  describe('AnalyticsService', () => {
    beforeEach(() => {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({ providers: [provideRouter([])] });
    });

    it('init() does not load gtag.js when GA4_MEASUREMENT_ID is unset', () => {
      /* The committed environment.ts carries the production GA4 id (the
         build-time prebuild rewrites it per deploy). This test locks in the
         *unset* gating behaviour, so force the id empty for the duration and
         restore it after — keeping the test hermetic regardless of what the
         checked-in environment happens to hold. */
      const env = environment as { GA4_MEASUREMENT_ID: string };
      const originalId = env.GA4_MEASUREMENT_ID;
      env.GA4_MEASUREMENT_ID = '';
      try {
        const before = document.querySelectorAll('script[src*="googletagmanager"]').length;
        const service = TestBed.inject(AnalyticsService);
        service.init();
        const after = document.querySelectorAll('script[src*="googletagmanager"]').length;
        expect(after).toBe(before);
        expect((window as unknown as { gtag?: unknown }).gtag).toBeUndefined();
      } finally {
        env.GA4_MEASUREMENT_ID = originalId;
      }
    });
  });

  describe('SentryErrorHandler', () => {
    let consoleError: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
      consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    });
    afterEach(() => {
      consoleError.mockRestore();
    });

    it('logs to console and does not throw when the SDK has not loaded', () => {
      const handler = new SentryErrorHandler();
      const err = new Error('boom');
      expect(() => handler.handleError(err)).not.toThrow();
      expect(consoleError).toHaveBeenCalledWith(err);
    });
  });
});
