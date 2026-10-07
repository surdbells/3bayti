import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { routes } from './app.routes';

/**
 * The Style Hub was removed. Every former /styles URL (hub, create, detail,
 * edit, and anything deeper) must land on the home page instead of a 404 or a
 * blank page, so shared and bookmarked links keep working.
 *
 * Navigates the real route table without a <router-outlet>, so no page
 * component is rendered; only the URL the router settles on is checked.
 */
describe('app routes: removed Style Hub redirects', () => {
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter(routes)] });
    router = TestBed.inject(Router);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
  });

  const legacyUrls = [
    '/styles',
    '/styles/',
    '/styles/create',
    '/styles/eid-look-a1b2',
    '/styles/eid-look-a1b2/edit',
    '/styles/eid-look-a1b2/some/deeper/path',
    '/styles?tab=mine',
    '/styles/eid-look-a1b2?utm_source=whatsapp',
  ];

  for (const url of legacyUrls) {
    it(`redirects ${url} to the home page`, async () => {
      const ok = await router.navigateByUrl(url);
      expect(ok).toBe(true);
      // The path is what matters (home); query params are not asserted.
      expect(router.url.split(/[?#]/)[0]).toBe('/');
    });
  }

  it('declares no routable /styles page (the parent only redirects)', () => {
    const stylesRoutes = routes.filter((r) => r.path === 'styles' || r.path?.startsWith('styles/'));
    expect(stylesRoutes).toHaveLength(1);
    const [parent] = stylesRoutes;
    expect(parent.loadComponent).toBeUndefined();
    expect(parent.component).toBeUndefined();
    expect(parent.children).toEqual([{ path: '**', redirectTo: '/' }]);
  });
});
