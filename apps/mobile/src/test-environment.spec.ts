import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient } from '@angular/common/http';
import { HttpTestingController } from '@angular/common/http/testing';

/**
 * Guards src/test.ts: no unit test may reach the real network (production
 * api-v3.3bayti.ae), whether it relies on the root-provided HttpClient or
 * calls provideHttpClient() itself.
 */
describe('Karma test environment', () => {
  const URL = 'https://api-v3.3bayti.ae/v3/auth/refresh';

  it('serves the root-provided HttpClient from the testing backend', () => {
    TestBed.configureTestingModule({});
    TestBed.inject(HttpClient).post(URL, { refresh_token: 'r' }).subscribe();
    const ctrl = TestBed.inject(HttpTestingController);
    ctrl.expectOne(URL).flush({});
    ctrl.verify();
  });

  it('also when a spec provides provideHttpClient() without the testing backend', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient()] });
    TestBed.inject(HttpClient).post(URL, { refresh_token: 'r' }).subscribe();
    const ctrl = TestBed.inject(HttpTestingController);
    ctrl.expectOne(URL).flush({});
    ctrl.verify();
  });
});
