import { provideAnimations } from "@angular/platform-browser/animations";
import {APP_INITIALIZER, ApplicationConfig, provideZoneChangeDetection} from '@angular/core';
import { provideRouter, withInMemoryScrolling } from '@angular/router';

import { routes } from './app.routes';
import {provideHotToastConfig} from './shared/toast/toast.service';
import {provideHttpClient} from '@angular/common/http';
import {I18nService} from './i18n.service';
function initI18n(i18n: I18nService) {
  return () => i18n.init();
}
export const appConfig: ApplicationConfig = {
  providers: [
    provideAnimations(),
    provideHttpClient(),
    provideHotToastConfig(),
    provideZoneChangeDetection({ eventCoalescing: true }),
    // Restore scroll position on back/forward navigation so users land where
    // they left off (instead of jumping to the top); jump to top on new pages.
    provideRouter(
      routes,
      withInMemoryScrolling({ scrollPositionRestoration: 'enabled', anchorScrolling: 'enabled' }),
    ),
    { provide: APP_INITIALIZER, useFactory: initI18n, deps: [I18nService], multi: true }
  ]
};

