// This file is required by karma.conf.js and loads recursively all the .spec and framework files

import 'zone.js/testing';
import { NgModule } from '@angular/core';
import { HttpXhrBackend } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { getTestBed } from '@angular/core/testing';
import {
  BrowserDynamicTestingModule,
  platformBrowserDynamicTesting
} from '@angular/platform-browser-dynamic/testing';

/**
 * Unit tests never reach the network. HttpClient is provided in root, so a
 * spec that builds a page or service on the REAL MobileNetworkAdapter would
 * otherwise send real requests to production (api-v3.3bayti.ae), including
 * POST /v3/auth/refresh for a stored user. Every spec gets Angular's testing
 * backend instead: unanswered requests just stay pending. HttpXhrBackend is
 * rerouted too, because a spec-level provideHttpClient() resolves its
 * backend through it. A spec that wants to answer requests injects
 * HttpTestingController (or provides its own provideHttpClientTesting()).
 */
@NgModule({
  providers: [
    provideHttpClientTesting(),
    { provide: HttpXhrBackend, useExisting: HttpTestingController },
  ],
})
class NoNetworkTestingModule {}

// First, initialize the Angular testing environment.
getTestBed().initTestEnvironment(
  [BrowserDynamicTestingModule, NoNetworkTestingModule],
  platformBrowserDynamicTesting(),
);
