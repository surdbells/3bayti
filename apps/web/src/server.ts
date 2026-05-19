import {
  AngularNodeAppEngine,
  createNodeRequestHandler,
  isMainModule,
  writeResponseToNodeResponse,
} from '@angular/ssr/node';
import express from 'express';
import { join } from 'node:path';
import { createAuthProxyRouter, createAuthProxyConfig } from './server/auth-proxy';

const browserDistFolder = join(import.meta.dirname, '../browser');

const app = express();
const angularApp = new AngularNodeAppEngine();

/**
 * Auth-proxy BFF — Y.1-C.
 *
 * Mounts the auth-proxy router under /auth-proxy. The router handles
 * 8 endpoints (login, register, confirm, send-otp, reset, reset-confirm,
 * refresh, logout, me) that the Angular AuthService calls via the
 * shared @3bayti/web HttpClient. Refresh tokens are parked as
 * HttpOnly cookies at this layer; they never enter the browser's JS
 * context. See ./server/auth-proxy/auth-proxy.routes.ts for details.
 *
 * Mounting BEFORE the Angular SSR catch-all is critical — otherwise
 * Angular would try to render a "page" at /auth-proxy/login and either
 * 404 or worse, leak HTML in response to an XHR.
 */
app.use('/auth-proxy', createAuthProxyRouter(createAuthProxyConfig()));

/**
 * Serve static files from /browser
 */
app.use(
  express.static(browserDistFolder, {
    maxAge: '1y',
    index: false,
    redirect: false,
  }),
);

/**
 * Handle all other requests by rendering the Angular application.
 */
app.use((req, res, next) => {
  angularApp
    .handle(req)
    .then((response) =>
      response ? writeResponseToNodeResponse(response, res) : next(),
    )
    .catch(next);
});

/**
 * Start the server if this module is the main entry point, or it is ran via PM2.
 * The server listens on the port defined by the `PORT` environment variable, or defaults to 4000.
 */
if (isMainModule(import.meta.url) || process.env['pm_id']) {
  const port = process.env['PORT'] || 4000;
  app.listen(port, (error) => {
    if (error) {
      throw error;
    }

    console.log(`Node Express server listening on http://localhost:${port}`);
  });
}

/**
 * Request handler used by the Angular CLI (for dev-server and during build) or Firebase Cloud Functions.
 */
export const reqHandler = createNodeRequestHandler(app);
