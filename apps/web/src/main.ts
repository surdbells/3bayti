import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';
import { initSentry } from './app/core/monitoring';

/* Initialise Sentry before bootstrap so init-time errors are captured.
   No-op unless SENTRY_DSN is set at build time. */
initSentry();

bootstrapApplication(App, appConfig)
  .catch((err) => console.error(err));
