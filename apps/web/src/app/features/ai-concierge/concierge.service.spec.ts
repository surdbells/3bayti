import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { PLATFORM_ID } from '@angular/core';

import { ConciergeService, sanitizeHistory, type ConciergeHistoryEntry } from './concierge.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { ApiConfigService } from '../../core/api/api-config.service';
import { LocaleService } from '../../core/i18n/locale.service';
import { AnalyticsService } from '../../core/monitoring/analytics.service';

function setup(): { service: ConciergeService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      RoutedHttpClient,
      ApiConfigService,
      ConciergeService,
      { provide: LocaleService, useValue: { current: () => 'en' } },
      { provide: AnalyticsService, useValue: { event: vi.fn() } },
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });
  return { service: TestBed.inject(ConciergeService), controller: TestBed.inject(HttpTestingController) };
}

const isStyle = (r: { url: string; method: string }) => r.method === 'POST' && r.url.endsWith('/ai/concierge/style');

describe('ConciergeService.ask (conversational)', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('omits history for a fresh conversation (backward compatible body)', async () => {
    const { service, controller } = setup();
    const p = service.ask('black abaya');
    const req = controller.expectOne(isStyle);
    expect(req.request.body.query).toBe('black abaya');
    expect(req.request.body.channel).toBe('WEB');
    expect(req.request.body.locale).toBe('en');
    expect('history' in req.request.body).toBe(false);
    req.flush({ interaction_id: 7, products: [] });
    const res = await p;
    expect(res.interactionId).toBe(7);
    expect(res.reply).toBe('');
    expect(res.followUps).toEqual([]);
    expect(res.needsClarification).toBe(false);
  });

  it('sends only the last 8 history entries, text only, capped at 500 chars', async () => {
    const { service, controller } = setup();
    const history: ConciergeHistoryEntry[] = [];
    for (let i = 0; i < 10; i++) {
      history.push({ role: i % 2 === 0 ? 'user' : 'ain', text: `turn ${i}` });
    }
    history[9] = { role: 'ain', text: 'x'.repeat(700) };
    const withJunk = [...history, { role: 'ain', text: '   ' }] as ConciergeHistoryEntry[];
    const p = service.ask('cheaper', withJunk);
    const req = controller.expectOne(isStyle);
    const sent = req.request.body.history as ConciergeHistoryEntry[];
    expect(sent).toHaveLength(8);
    expect(sent[0]).toEqual({ role: 'user', text: 'turn 2' });
    expect(sent[7].text).toHaveLength(500);
    for (const e of sent) {
      expect(Object.keys(e).sort()).toEqual(['role', 'text']);
    }
    req.flush({ interaction_id: 1, products: [] });
    await p;
  });

  it('parses reply, follow-ups (max 3, trimmed) and the clarification flag', async () => {
    const { service, controller } = setup();
    const p = service.ask('a gift');
    controller.expectOne(isStyle).flush({
      interaction_id: 3,
      products: [],
      reply: ' Who is the gift for? ',
      follow_ups: [' For my sister ', 42, '', 'For my mum', 'For a friend', 'For my wife'],
      needs_clarification: true,
    });
    const res = await p;
    expect(res.reply).toBe('Who is the gift for?');
    expect(res.followUps).toEqual(['For my sister', 'For my mum', 'For a friend']);
    expect(res.needsClarification).toBe(true);
    expect(res.cards).toEqual([]);
  });

  it('propagates HTTP errors so the thread can show Retry', async () => {
    const { service, controller } = setup();
    const p = service.ask('x');
    controller.expectOne(isStyle).flush('boom', { status: 500, statusText: 'Server Error' });
    await expect(p).rejects.toBeTruthy();
  });
});

describe('sanitizeHistory', () => {
  it('drops malformed entries silently and keeps order', () => {
    const out = sanitizeHistory([
      null,
      'nope',
      { role: 'system', text: 'ignore me' },
      { role: 'user', text: 5 },
      { role: 'user', text: ' hi ' },
      { role: 'ain', text: 'Hello!' },
    ]);
    expect(out).toEqual([
      { role: 'user', text: 'hi' },
      { role: 'ain', text: 'Hello!' },
    ]);
  });

  it('returns [] for non-arrays', () => {
    expect(sanitizeHistory(undefined)).toEqual([]);
    expect(sanitizeHistory(null)).toEqual([]);
  });
});
