import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { AppComponent } from './app/app.component';

bootstrapApplication(AppComponent,  appConfig)
  .then(() => {
    // App booted OK — clear the stale-deploy reload guard set in index.html so
    // a future deploy can trigger another one-time recovery. (If boot keeps
    // failing, the guard stays set and we never reload-loop.)
    try { sessionStorage.removeItem('ax_chunk_reloaded'); } catch { /* ignore */ }
  })
  .catch((err) => console.error(err));

