/**
 * Vitest global test setup for apps/web.
 *
 * Solves four problems:
 * 1. TestBed never initialised → BrowserDynamicTestingModule + zone.js
 * 2. JIT compiler not loaded → import '@angular/compiler' first
 * 3. styleUrl/templateUrl can't load in jsdom:
 *    - CSS/SCSS → return empty string (styles don't affect assertions)
 *    - HTML templateUrl → read from disk (empty HTML = empty host element)
 * 4. Constructor-injection NG0202 → reflect-metadata + tsc emitDecoratorMetadata
 */

// ── Import order is critical ────────────────────────────────────────────────
import 'zone.js';
import 'zone.js/testing';
import '@angular/compiler';
import 'reflect-metadata';

import { readFileSync } from 'fs';
import { resolve } from 'path';
import { execSync } from 'child_process';
import { beforeEach } from 'vitest';
import { getTestBed } from '@angular/core/testing';
import {
  BrowserDynamicTestingModule,
  platformBrowserDynamicTesting,
} from '@angular/platform-browser-dynamic/testing';
import { ɵresolveComponentResources } from '@angular/core';

// ── Template HTML file resolver ──────────────────────────────────────────────
// Angular JIT passes templateUrl as a relative path (e.g. './header.html').
// We find the actual file under src/ by its basename.
const _htmlCache = new Map<string, string>();
function resolveHtmlFile(url: string): string {
  const basename = url.replace(/^\.\//, '').split('?')[0];
  if (_htmlCache.has(basename)) return _htmlCache.get(basename)!;
  try {
    const srcDir = resolve(process.cwd(), 'src');
    const found = execSync(
      'find ' + srcDir + ' -name "' + basename + '" -not -path "*/node_modules/*" 2>/dev/null | head -1',
      { encoding: 'utf-8', timeout: 3000 },
    ).trim();
    const content = found ? readFileSync(found, 'utf-8') : '';
    _htmlCache.set(basename, content);
    return content;
  } catch {
    _htmlCache.set(basename, '');
    return '';
  }
}

// ── Stub fetch for Angular's JIT resource loader ──────────────────────────────
const _nativeFetch = globalThis.fetch?.bind(globalThis);
globalThis.fetch = async (
  input: RequestInfo | URL,
  init?: RequestInit,
): Promise<Response> => {
  const url =
    typeof input === 'string'
      ? input
      : input instanceof URL
        ? input.href
        : (input as Request).url;

  if (/\.(scss|css)(\?.*)?$/.test(url)) {
    return new Response('', { status: 200, headers: { 'Content-Type': 'text/plain' } });
  }

  if (/\.html(\?.*)?$/.test(url)) {
    const content = url.startsWith('/')
      ? readFileSync(url.split('?')[0], 'utf-8').toString()
      : resolveHtmlFile(url);
    return new Response(content, { status: 200, headers: { 'Content-Type': 'text/html' } });
  }

  return _nativeFetch ? _nativeFetch(input, init) : new Response('', { status: 200 });
};

// ── TestBed initialisation ────────────────────────────────────────────────────
getTestBed().initTestEnvironment(
  BrowserDynamicTestingModule,
  platformBrowserDynamicTesting(),
  { teardown: { destroyAfterEach: false } },
);

// ── Drain JIT resource queue before each test ─────────────────────────────────
beforeEach(async () => {
  await ɵresolveComponentResources(async (url: string) => fetch(url));
});

// ── Patch createComponent: double-tick for embedded-view signals ──────────────
// *ngIf else-template embedded views need a second detectChanges() to evaluate
// signals inside them after the outer view stamps the template.
const _tb = getTestBed();
const _origCreate = _tb.createComponent.bind(_tb);
// eslint-disable-next-line @typescript-eslint/no-explicit-any
(_tb as any).createComponent = <T>(...args: Parameters<typeof _tb.createComponent<T>>) => {
  const fixture = _origCreate<T>(...args);
  const _origDetect = fixture.detectChanges.bind(fixture);
  fixture.detectChanges = (checkNoChanges?: boolean): void => {
    _origDetect(checkNoChanges);
    try { _origDetect(false); } catch { /* ignore */ }
  };
  return fixture;
};
