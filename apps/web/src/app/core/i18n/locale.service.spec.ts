import { TestBed } from '@angular/core/testing';
import { DOCUMENT, Provider, EnvironmentProviders } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TranslateService } from '@ngx-translate/core';
import { LocaleService } from './locale.service';
import { provideI18n } from './i18n.providers';
import { LOCALE_COOKIE } from './locale.types';

/**
 * A minimal DOM stub good enough for LocaleService's needs:
 *   - documentElement.setAttribute (for <html lang dir>)
 *   - cookie getter/setter
 *   - defaultView.navigator.language
 *   - defaultView.location.protocol
 */
interface FakeDoc {
  documentElement: { setAttribute: (k: string, v: string) => void; getAttribute?: (k: string) => string | null };
  cookie: string;
  defaultView?: { navigator: { language: string }; location: { protocol: string } };
}

function fakeDocument(opts: {
  cookie?: string;
  navigatorLanguage?: string;
  protocol?: string;
} = {}): FakeDoc {
  const attrs: Record<string, string> = {};
  return {
    documentElement: {
      setAttribute: (k, v) => {
        attrs[k] = v;
      },
      getAttribute: k => attrs[k] ?? null,
    },
    cookie: opts.cookie ?? '',
    defaultView: {
      navigator: { language: opts.navigatorLanguage ?? 'en-US' },
      location: { protocol: opts.protocol ?? 'http:' },
    },
  };
}

/**
 * Configure a fresh TestBed with an overridable DOCUMENT. The storefront
 * is a pure CSR SPA, so locale resolution is browser-only (cookie →
 * navigator.language → default). We bring along the full provideI18n()
 * bundle so TranslateService is wired the same way as in the live app;
 * translation HTTP calls are mocked via HttpClientTesting.
 */
function setupService(opts: { document: FakeDoc }): { service: LocaleService } {
  const providers: (Provider | EnvironmentProviders)[] = [
    provideHttpClient(),
    provideHttpClientTesting(),
    provideI18n(),
    { provide: DOCUMENT, useValue: opts.document },
  ];

  TestBed.resetTestingModule();
  TestBed.configureTestingModule({ providers });

  /* Stub TranslateService.use to avoid the real HTTP fetch. */
  const translate = TestBed.inject(TranslateService);
  translate.use = () => ({
    subscribe: (cb: (v: unknown) => void) => {
      cb({});
      return { unsubscribe: () => {} };
    },
  }) as unknown as ReturnType<TranslateService['use']>;

  return { service: TestBed.inject(LocaleService) };
}

describe('LocaleService', () => {
  describe('resolution', () => {
    it('uses bayti_locale cookie when present in document.cookie', async () => {
      const doc = fakeDocument({ cookie: 'foo=bar; bayti_locale=ar; other=baz' });
      const { service } = setupService({ document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
      // Direction is pinned to LTR even for Arabic (product decision).
      expect(service.dir()).toBe('ltr');
      expect(service.isRtl()).toBe(false);
    });

    it('falls back to navigator.language when no cookie is present', async () => {
      const doc = fakeDocument({ navigatorLanguage: 'ar-AE' });
      const { service } = setupService({ document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });

    it('falls back to DEFAULT_LOCALE on unsupported navigator.language', async () => {
      const doc = fakeDocument({ navigatorLanguage: 'fr-FR' });
      const { service } = setupService({ document: doc });

      await service.initialize();

      expect(service.current()).toBe('en');
    });

    it('handles malformed cookie strings without throwing', async () => {
      const doc = fakeDocument({ cookie: '====; ;;bayti_locale=ar;malformed' });
      const { service } = setupService({ document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });

    it('decodes URI-encoded cookie values defensively', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=ar' });
      const { service } = setupService({ document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });

    it('sets <html lang> and <html dir> attributes on the document', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=ar' });
      const { service } = setupService({ document: doc });

      await service.initialize();

      expect(doc.documentElement.getAttribute?.('lang')).toBe('ar');
      // Arabic keeps lang="ar" for fonts/screen readers but dir stays LTR.
      expect(doc.documentElement.getAttribute?.('dir')).toBe('ltr');
    });
  });

  describe('cookie persistence', () => {
    it('writes document.cookie with persistence attributes', async () => {
      const doc = fakeDocument({ navigatorLanguage: 'ar' });
      const { service } = setupService({ document: doc });

      await service.initialize();

      expect(doc.cookie).toContain(`${LOCALE_COOKIE}=ar`);
      expect(doc.cookie).toContain('Path=/');
      expect(doc.cookie).toContain('Max-Age=');
    });

    it('omits Secure over http and includes it over https', async () => {
      const httpDoc = fakeDocument({ navigatorLanguage: 'ar', protocol: 'http:' });
      const { service: httpSvc } = setupService({ document: httpDoc });
      await httpSvc.initialize();
      expect(httpDoc.cookie).not.toContain('Secure');

      const httpsDoc = fakeDocument({ navigatorLanguage: 'ar', protocol: 'https:' });
      const { service: httpsSvc } = setupService({ document: httpsDoc });
      await httpsSvc.initialize();
      expect(httpsDoc.cookie).toContain('Secure');
    });
  });

  describe('setLocale', () => {
    it('updates the signal and applies new direction', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=en' });
      const { service } = setupService({ document: doc });

      await service.initialize();
      expect(service.current()).toBe('en');

      await service.setLocale('ar');

      expect(service.current()).toBe('ar');
      expect(service.dir()).toBe('ltr');
      expect(doc.cookie).toContain(`${LOCALE_COOKIE}=ar`);
    });

    it('is a no-op when the new locale equals the current locale', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=en' });
      const { service } = setupService({ document: doc });

      await service.initialize();
      const before = doc.cookie;
      await service.setLocale('en');

      /* Cookie value unchanged. */
      expect(doc.cookie).toBe(before);
    });

    it('silently ignores unsupported locale codes', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=en' });
      const { service } = setupService({ document: doc });

      await service.initialize();
      /* @ts-expect-error — passing intentionally invalid type to test runtime guard. */
      await service.setLocale('zh');

      expect(service.current()).toBe('en');
    });
  });

  describe('Reactive signals', () => {
    it('keeps dir LTR and isRtl false for every locale', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=ar' });
      const { service } = setupService({ document: doc });

      await service.initialize();
      expect(service.current()).toBe('ar');
      expect(service.dir()).toBe('ltr');
      expect(service.isRtl()).toBe(false);

      await service.setLocale('en');
      expect(service.current()).toBe('en');
      expect(service.dir()).toBe('ltr');
      expect(service.isRtl()).toBe(false);
    });
  });
});
