#!/usr/bin/env node
/**
 * Prebuild — inject build-time configuration into src/environments/environment.ts.
 *
 * Reads environment variables at build time and writes them as TypeScript
 * `as const` literals into a file the rest of the codebase imports from.
 *
 * This sidesteps Angular 21+'s deprecation of `fileReplacements` while giving
 * us the same outcome: typed, build-time configuration without runtime fetches.
 *
 * Run automatically by the `prebuild` npm script before every `ng build`.
 *
 * Environment variables consumed:
 *   SITE_URL            Canonical site URL, no trailing slash.
 *                       Default: https://staging.3bayti.ae (interim staging)
 *                       Production: set SITE_URL=https://3bayti.ae in
 *                       Cloudflare Pages once the production domain is wired.
 *
 *   SENTRY_DSN          Browser Sentry DSN for frontend error reporting.
 *                       Default: '' (unset → Sentry is a no-op; nothing is
 *                       sent). The DSN is a public client key and is safe to
 *                       ship in the browser bundle. Set per-environment in
 *                       Cloudflare Pages.
 *
 *   GA4_MEASUREMENT_ID  Google Analytics 4 measurement id, e.g. G-XXXXXXXXXX.
 *                       Default: '' (unset → gtag.js is never loaded). Set
 *                       per-environment in Cloudflare Pages.
 *
 *   FIREBASE_API_KEY            Firebase Web API key. Powers Google + Apple
 *                               social sign-in (Firebase Auth popups). If
 *                               EMPTY, the firebase init is skipped and the
 *                               social buttons hide — the app never crashes.
 *   FIREBASE_AUTH_DOMAIN        Firebase auth domain (e.g. bayti-bcc5e.firebaseapp.com).
 *   FIREBASE_PROJECT_ID         Firebase project id. Default: bayti-bcc5e.
 *   FIREBASE_APP_ID             Firebase web app id.
 *   FIREBASE_MESSAGING_SENDER_ID  Firebase messaging sender id (project number).
 *
 * If you add a new env var here, also document it in environment.ts so
 * editors can autocomplete it and reviewers can see what's in scope.
 */

import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ENV_FILE = join(__dirname, '..', 'src', 'environments', 'environment.ts');

/* ----- Read env vars with defaults ----- */
const SITE_URL = (process.env.SITE_URL || 'https://staging.3bayti.ae').replace(/\/$/, '');
const SENTRY_DSN = (process.env.SENTRY_DSN || 'https://822503d1eda33a1e983a6aa0a8f9dce7@o4511365625872384.ingest.us.sentry.io/4511365627772928').trim();
const GA4_MEASUREMENT_ID = (process.env.GA4_MEASUREMENT_ID || 'G-W2YF72TS3F').trim();
/* Mobile app store listing URLs for the home "Get the app" section.
   Default '#' renders a non-navigating "coming soon" badge. */
const APP_STORE_URL = (process.env.APP_STORE_URL || 'https://apps.apple.com/ar/app/3bayti/id6752422907').trim();
const PLAY_STORE_URL = (process.env.PLAY_STORE_URL || 'https://play.google.com/store/apps/details?id=ae.threebayti.app').trim();

/* Firebase Web config — powers Google + Apple social sign-in. Every value
   defaults to '' EXCEPT projectId (bayti-bcc5e) so a missing config is a
   no-op rather than a crash: firebase.init only initialises when apiKey is
   non-empty (see core/firebase/firebase.init.ts). Set these per-environment
   in Cloudflare Pages. The Web API key is a public client identifier — it is
   safe to ship in the browser bundle (restrict it by HTTP referrer + enable
   only the Identity Toolkit API in the Google Cloud console). */
const FIREBASE_API_KEY = (process.env.FIREBASE_API_KEY || '').trim();
const FIREBASE_AUTH_DOMAIN = (process.env.FIREBASE_AUTH_DOMAIN || '').trim();
const FIREBASE_PROJECT_ID = (process.env.FIREBASE_PROJECT_ID || 'bayti-bcc5e').trim();
const FIREBASE_APP_ID = (process.env.FIREBASE_APP_ID || '').trim();
const FIREBASE_MESSAGING_SENDER_ID = (process.env.FIREBASE_MESSAGING_SENDER_ID || '').trim();

/* Validate: SITE_URL must be a real https:// URL with no path. */
try {
  const url = new URL(SITE_URL);
  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    throw new Error(`SITE_URL must use http:// or https:// — got "${SITE_URL}"`);
  }
  if (url.pathname !== '/' && url.pathname !== '') {
    throw new Error(`SITE_URL must not include a path — got "${SITE_URL}"`);
  }
} catch (err) {
  console.error(`[inject-environment] Invalid SITE_URL: ${err.message}`);
  process.exit(1);
}

/* Soft validation: warn (don't fail) if monitoring values look malformed,
   so a typo is visible in the build log but never blocks a deploy. */
if (SENTRY_DSN && !/^https:\/\/.+@.+\/.+/.test(SENTRY_DSN)) {
  console.warn(`[inject-environment] SENTRY_DSN is set but does not look like a Sentry DSN; passing through anyway.`);
}
if (GA4_MEASUREMENT_ID && !/^G-[A-Z0-9]+$/.test(GA4_MEASUREMENT_ID)) {
  console.warn(`[inject-environment] GA4_MEASUREMENT_ID is set but does not match the G-XXXXXXXX format; passing through anyway.`);
}

const fileBody = `/**
 * AUTO-GENERATED by scripts/inject-environment.mjs at build time.
 *
 * DO NOT EDIT THIS FILE BY HAND. Your changes will be overwritten on the
 * next build. To change values, set environment variables before running
 * the build:
 *   SITE_URL=https://example.com SENTRY_DSN=... GA4_MEASUREMENT_ID=G-... npm run build
 *
 * Source-of-truth env vars are listed in scripts/inject-environment.mjs.
 */

export const environment = {
  SITE_URL: ${JSON.stringify(SITE_URL)},
  SENTRY_DSN: ${JSON.stringify(SENTRY_DSN)},
  GA4_MEASUREMENT_ID: ${JSON.stringify(GA4_MEASUREMENT_ID)},

  /* Google Places API (New) — powers the street-address autocomplete on the
     checkout/address form (apps/web/src/app/core/places). If apiKey is empty
     the autocomplete degrades gracefully to a plain text input.

     SECURITY: this is currently the same key the mobile app ships. For web it
     SHOULD be replaced with an HTTP-referrer-restricted web key (restricted to
     the 3bayti.ae origins) + a usage quota cap in Google Cloud Console. */
  googlePlaces: {
    apiKey: 'AIzaSyAHERMyCn9KfrhZF5zpKynzLp0SjXpQpKU',
    /* ISO 3166-1 alpha-2 country code(s) to restrict autocomplete to. */
    regions: ['AE'],
  },

  /* Mobile app store listings for the home "Get the app" section. Set
     APP_STORE_URL / PLAY_STORE_URL at build time; '#' renders a
     non-navigating "coming soon" badge. */
  appStores: {
    appStore: ${JSON.stringify(APP_STORE_URL)},
    playStore: ${JSON.stringify(PLAY_STORE_URL)},
  },

  /* Firebase Web config — Google + Apple social sign-in. When apiKey is ''
     the firebase init is skipped (core/firebase/firebase.init.ts guards on
     it) and the social-login buttons hide. Set FIREBASE_* env vars at build
     time in Cloudflare Pages to enable it. */
  firebase: {
    apiKey: ${JSON.stringify(FIREBASE_API_KEY)},
    authDomain: ${JSON.stringify(FIREBASE_AUTH_DOMAIN)},
    projectId: ${JSON.stringify(FIREBASE_PROJECT_ID)},
    appId: ${JSON.stringify(FIREBASE_APP_ID)},
    messagingSenderId: ${JSON.stringify(FIREBASE_MESSAGING_SENDER_ID)},
  },
} as const;
`;

writeFileSync(ENV_FILE, fileBody, 'utf8');
console.log(`[inject-environment] wrote ${ENV_FILE}`);
console.log(`[inject-environment]   SITE_URL           = ${SITE_URL}`);
console.log(`[inject-environment]   SENTRY_DSN         = ${SENTRY_DSN ? '(set)' : '(unset — Sentry disabled)'}`);
console.log(`[inject-environment]   GA4_MEASUREMENT_ID = ${GA4_MEASUREMENT_ID || '(unset — GA4 disabled)'}`);
console.log(`[inject-environment]   FIREBASE           = ${FIREBASE_API_KEY ? `(set, project ${FIREBASE_PROJECT_ID})` : '(unset — social sign-in disabled)'}`);
