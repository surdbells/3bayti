import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter } from '@angular/router';

import { routes } from './app.routes';

@Component({ standalone: true, template: '' })
class HomeTabStubComponent {}

/**
 * The Style Hub was removed. Every URL it used to own must land on the
 * customer home tab (/account) instead of failing to match. Runs against the
 * real route table, with /account swapped for a stub so the real page (and
 * its network calls) never loads.
 */
describe('app routes: removed Style Hub URLs', () => {
  const testRoutes: Routes = routes.map((route) =>
    route.path === 'account' ? { path: 'account', component: HomeTabStubComponent } : route,
  );

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter(testRoutes)] });
  });

  const formerUrls = [
    '/styles',
    '/style-view',
    '/style-view/eid-look',
    '/style-edit/eid-look',
    '/create',
  ];

  for (const url of formerUrls) {
    it(`redirects ${url} to the home tab`, async () => {
      const router = TestBed.inject(Router);
      const navigated = await router.navigateByUrl(url);
      expect(navigated).toBeTrue();
      expect(router.url).toBe('/account');
    });
  }
});
