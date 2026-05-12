import { provideAnimations } from "@angular/platform-browser/animations";
import {APP_INITIALIZER, ApplicationConfig, provideZoneChangeDetection} from '@angular/core';
import { provideRouter } from '@angular/router';

import { routes } from './app.routes';
import {provideHotToastConfig} from '@ngneat/hot-toast';
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
    provideRouter(routes),
    { provide: APP_INITIALIZER, useFactory: initI18n, deps: [I18nService], multi: true }
  ]
};

