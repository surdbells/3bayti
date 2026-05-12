import { bootstrapApplication } from '@angular/platform-browser';
import { RouteReuseStrategy, provideRouter, withPreloading, PreloadAllModules } from '@angular/router';
import { IonicRouteStrategy, provideIonicAngular } from '@ionic/angular/standalone';
import { register } from 'swiper/element/bundle';

import { routes } from './app/app.routes';
import { AppComponent } from './app/app.component';
import {provideHttpClient} from "@angular/common/http";
import {I18nService} from "./app/i18n.service";
import {APP_INITIALIZER, provideZoneChangeDetection} from "@angular/core";
import {provideAxIcons} from "./app/shared/ax-mobile/icon";
function initI18n(i18n: I18nService) {
  return () => i18n.init();
}
register();
bootstrapApplication(AppComponent, {
  providers: [provideZoneChangeDetection(),
    {provide: RouteReuseStrategy, useClass: IonicRouteStrategy},
    {provide: APP_INITIALIZER, useFactory: initI18n, deps: [I18nService], multi: true},
    provideIonicAngular({animated: true}),
    provideHttpClient(),
    provideAxIcons(),
    provideRouter(routes, withPreloading(PreloadAllModules)),
  ],
});
