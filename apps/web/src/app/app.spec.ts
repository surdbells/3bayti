import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { App } from './app';

/**
 * Smoke test for the root App shell.
 *
 * The shell renders <app-header>, <router-outlet>, and <app-footer>.
 * Header/footer are tested in their own specs; here we only assert
 * that the component bootstraps with its required providers wired.
 *
 * Note: this file previously contained an `ng new`-style template
 * assertion against text that no longer exists in the shell ("Hello,
 * 3bayti-web"). Repaired drive-by in Y.1-A; the new assertion
 * matches what the shell actually renders. Also added providers
 * required by the new header (TranslateService via provideTranslateService,
 * Router via provideRouter, HttpClient for the translation loader).
 */
describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService({ fallbackLang: 'en', lang: 'en' }),
      ],
    }).compileComponents();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(App);
    const app = fixture.componentInstance;
    expect(app).toBeTruthy();
  });

  it('should render the persistent layout shell', () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    const host = fixture.nativeElement as HTMLElement;
    expect(host.querySelector('app-header')).not.toBeNull();
    expect(host.querySelector('router-outlet')).not.toBeNull();
    expect(host.querySelector('app-footer')).not.toBeNull();
  });
});
