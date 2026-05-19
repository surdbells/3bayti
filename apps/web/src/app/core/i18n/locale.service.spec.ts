import { TestBed } from '@angular/core/testing';
import {
  PLATFORM_ID,
  REQUEST,
  RESPONSE_INIT,
  DOCUMENT,
  Provider,
  EnvironmentProviders,
} from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TranslateService } from '@ngx-translate/core';
import { LocaleService } from './locale.service';
import { provideI18n } from './i18n.providers';
import { LOCALE_COOKIE } from './locale.types';

/**
 * Test helpers — build a fake Request matching the global Fetch API
 * Request type that Angular's REQUEST token expects.
 */
function fakeRequest(headers: Record<string, string> = {}, url = 'https://staging.3bayti.ae/'): Request {
  return new Request(url, { headers });
}

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
 * Configure a fresh TestBed with overridable platform / request / document.
 * We bring along the full provideI18n() bundle so TranslateService is
 * wired the same way as in the live app. Translation HTTP calls are
 * mocked via HttpClientTesting.
 */
function setupService(opts: {
  platform: 'browser' | 'server';
  request?: Request | null;
  responseInit?: ResponseInit | null;
  document: FakeDoc;
}): { service: LocaleService; responseInit: ResponseInit | null } {
  const responseInit: ResponseInit | null = opts.responseInit ?? (opts.platform === 'server' ? { headers: new Headers() } : null);

  const providers: (Provider | EnvironmentProviders)[] = [
    provideHttpClient(),
    provideHttpClientTesting(),
    provideI18n(),
    { provide: PLATFORM_ID, useValue: opts.platform },
    { provide: REQUEST, useValue: opts.request ?? null },
    { provide: RESPONSE_INIT, useValue: responseInit },
    { provide: DOCUMENT, useValue: opts.document },
  ];

  /* Reset and configure. */
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({ providers });

  /* Stub TranslateService.use to avoid the real HTTP fetch. */
  const translate = TestBed.inject(TranslateService);
  translate.use = () => ({
    /* Minimal Observable-like — LocaleService.initialize calls
       firstValueFrom on this; returning a thenable lets the await
       resolve immediately. */
    subscribe: (cb: (v: unknown) => void) => {
      cb({});
      return { unsubscribe: () => {} };
    },
    /* In RxJS Observables expose [Symbol.asyncIterator] via toPromise/firstValueFrom;
       here we cheat and just resolve immediately. */
  }) as unknown as ReturnType<TranslateService['use']>;

  return { service: TestBed.inject(LocaleService), responseInit };
}

describe('LocaleService', () => {
  describe('SSR resolution', () => {
    it('uses bayti_locale cookie when present', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ cookie: 'bayti_locale=ar; foo=bar' });
      const { service } = setupService({ platform: 'server', request: req, document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
      expect(service.dir()).toBe('rtl');
      expect(service.isRtl()).toBe(true);
    });

    it('falls back to Accept-Language when no cookie is set', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ 'accept-language': 'ar-AE,ar;q=0.9,en;q=0.8' });
      const { service } = setupService({ platform: 'server', request: req, document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });

    it('honors EN preference even when both cookie and header carry it', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ cookie: 'bayti_locale=en', 'accept-language': 'en-GB,en' });
      const { service } = setupService({ platform: 'server', request: req, document: doc });

      await service.initialize();

      expect(service.current()).toBe('en');
      expect(service.dir()).toBe('ltr');
    });

    it('falls back to DEFAULT_LOCALE when neither cookie nor accept-language provide a supported locale', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ 'accept-language': 'de-DE,de;q=0.8,fr;q=0.6' });
      const { service } = setupService({ platform: 'server', request: req, document: doc });

      await service.initialize();

      expect(service.current()).toBe('en');
    });

    it('ignores unsupported cookie values without throwing', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ cookie: 'bayti_locale=zh', 'accept-language': 'ar' });
      const { service } = setupService({ platform: 'server', request: req, document: doc });

      await service.initialize();

      /* Unsupported cookie → fall through to Accept-Language. */
      expect(service.current()).toBe('ar');
    });

    it('writes Set-Cookie on RESPONSE_INIT.headers during initialize', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ 'accept-language': 'ar' }, 'http://localhost:4000/');
      const responseInit: ResponseInit = { headers: new Headers() };
      const { service } = setupService({
        platform: 'server',
        request: req,
        responseInit,
        document: doc,
      });

      await service.initialize();

      const headers = responseInit.headers as Headers;
      const setCookie = headers.get('set-cookie');
      expect(setCookie).not.toBeNull();
      expect(setCookie).toContain(`${LOCALE_COOKIE}=ar`);
      expect(setCookie).toContain('Path=/');
      expect(setCookie).toContain('SameSite=Lax');
      /* Request URL is http://; Secure should be absent. */
      expect(setCookie).not.toContain('Secure');
    });

    it('includes Secure attribute on Set-Cookie when request is https', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ 'accept-language': 'ar' }, 'https://staging.3bayti.ae/');
      const responseInit: ResponseInit = { headers: new Headers() };
      const { service } = setupService({
        platform: 'server',
        request: req,
        responseInit,
        document: doc,
      });

      await service.initialize();
      const setCookie = (responseInit.headers as Headers).get('set-cookie');
      expect(setCookie).toContain('Secure');
    });

    it('respects x-forwarded-proto for Secure when running behind a proxy', async () => {
      const doc = fakeDocument();
      const req = fakeRequest({ 'x-forwarded-proto': 'https', 'accept-language': 'ar' }, 'http://internal/');
      const responseInit: ResponseInit = { headers: new Headers() };
      const { service } = setupService({
        platform: 'server',
        request: req,
        responseInit,
        document: doc,
      });

      await service.initialize();
      expect((responseInit.headers as Headers).get('set-cookie')).toContain('Secure');
    });

    it('sets <html lang> and <html dir> attributes on the document', async () => {
      const setSpy = vi.fn();
      const doc: FakeDoc = {
        documentElement: { setAttribute: setSpy },
        cookie: '',
      };
      const req = fakeRequest({ cookie: 'bayti_locale=ar' });
      const { service } = setupService({ platform: 'server', request: req, document: doc });

      await service.initialize();

      expect(setSpy).toHaveBeenCalledWith('lang', 'ar');
      expect(setSpy).toHaveBeenCalledWith('dir', 'rtl');
    });
  });

  describe('Browser resolution', () => {
    it('uses bayti_locale cookie when present in document.cookie', async () => {
      const doc = fakeDocument({ cookie: 'foo=bar; bayti_locale=ar; other=baz' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });

    it('falls back to navigator.language when no cookie is present', async () => {
      const doc = fakeDocument({ navigatorLanguage: 'ar-AE' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });

    it('falls back to DEFAULT_LOCALE on unsupported navigator.language', async () => {
      const doc = fakeDocument({ navigatorLanguage: 'fr-FR' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();

      expect(service.current()).toBe('en');
    });

    it('handles malformed cookie strings without throwing', async () => {
      const doc = fakeDocument({ cookie: '====; ;;bayti_locale=ar;malformed' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });

    it('writes document.cookie with persistence attributes', async () => {
      const doc = fakeDocument({ navigatorLanguage: 'ar' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();

      expect(doc.cookie).toContain(`${LOCALE_COOKIE}=ar`);
      expect(doc.cookie).toContain('Path=/');
      expect(doc.cookie).toContain('Max-Age=');
    });

    it('decodes URI-encoded cookie values defensively', async () => {
      /* A locale value should never be URI-encoded, but if upstream
         encodes it for some reason, we should still resolve it. */
      const doc = fakeDocument({ cookie: 'bayti_locale=ar' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();

      expect(service.current()).toBe('ar');
    });
  });

  describe('setLocale', () => {
    it('updates the signal and applies new direction', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=en' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();
      expect(service.current()).toBe('en');

      await service.setLocale('ar');

      expect(service.current()).toBe('ar');
      expect(service.dir()).toBe('rtl');
      expect(doc.cookie).toContain(`${LOCALE_COOKIE}=ar`);
    });

    it('is a no-op when the new locale equals the current locale', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=en' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();
      const before = doc.cookie;
      await service.setLocale('en');

      /* Cookie value unchanged. */
      expect(doc.cookie).toBe(before);
    });

    it('silently ignores unsupported locale codes', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=en' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();
      /* @ts-expect-error — passing intentionally invalid type to test runtime guard. */
      await service.setLocale('zh');

      expect(service.current()).toBe('en');
    });
  });

  describe('Reactive signals', () => {
    it('current, dir, and isRtl stay in sync', async () => {
      const doc = fakeDocument({ cookie: 'bayti_locale=ar' });
      const { service } = setupService({ platform: 'browser', document: doc });

      await service.initialize();
      expect(service.current()).toBe('ar');
      expect(service.dir()).toBe('rtl');
      expect(service.isRtl()).toBe(true);

      await service.setLocale('en');
      expect(service.current()).toBe('en');
      expect(service.dir()).toBe('ltr');
      expect(service.isRtl()).toBe(false);
    });
  });
});
