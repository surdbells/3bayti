import { Injectable, inject, DOCUMENT } from '@angular/core';
import { Router, NavigationEnd } from '@angular/router';
import { filter } from 'rxjs';
import { environment } from '../../../environments/environment';

declare global {
  interface Window {
    dataLayer: unknown[];
    gtag: (...args: unknown[]) => void;
  }
}

/**
 * Google Analytics 4 (gtag.js).
 *
 * No-op unless GA4_MEASUREMENT_ID is set at build time. SPA-aware: gtag
 * is configured with send_page_view:false and we emit a page_view on
 * every router NavigationEnd (the first navigation included), so client
 * routing is tracked the same as full page loads.
 *
 * init() is invoked once from an APP_INITIALIZER (see provideMonitoring()).
 */
@Injectable({ providedIn: 'root' })
export class AnalyticsService {
  private readonly router = inject(Router);
  private readonly document = inject(DOCUMENT);

  init(): void {
    const id = environment.GA4_MEASUREMENT_ID;
    if (!id) return;

    const win = this.document.defaultView as (Window & typeof globalThis) | null;
    if (!win) return;

    /* Inject the gtag.js loader. */
    const loader = this.document.createElement('script');
    loader.async = true;
    loader.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    this.document.head.appendChild(loader);

    /* Standard gtag bootstrap shim. */
    win.dataLayer = win.dataLayer || [];
    win.gtag = function gtag() {
      // eslint-disable-next-line prefer-rest-params
      win.dataLayer.push(arguments);
    };
    win.gtag('js', new Date());
    /* send_page_view:false, we emit page_view manually per SPA route. */
    win.gtag('config', id, { send_page_view: false });

    this.router.events
      .pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd))
      .subscribe((e) => {
        win.gtag('event', 'page_view', {
          page_path: e.urlAfterRedirects,
          page_location: win.location?.href,
          page_title: this.document.title,
        });
      });
  }
}
