import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { PLATFORM_ID } from '@angular/core';

import {
  CONCIERGE_MAX_QUESTIONS,
  CONCIERGE_SLOTS,
  ConciergeService,
  countAskedQuestions,
  isShowNowChip,
  sanitizeHistory,
  sanitizeSlots,
  toQuestionSlot,
  type ConciergeHistoryEntry,
} from './concierge.service';
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

  it('sends only the last 12 history entries, text only, capped at 500 chars', async () => {
    const { service, controller } = setup();
    const history: ConciergeHistoryEntry[] = [];
    for (let i = 0; i < 14; i++) {
      history.push({ role: i % 2 === 0 ? 'user' : 'ain', text: `turn ${i}` });
    }
    history[13] = { role: 'ain', text: 'x'.repeat(700) };
    const withJunk = [...history, { role: 'ain', text: '   ' }] as ConciergeHistoryEntry[];
    const p = service.ask('cheaper', withJunk);
    const req = controller.expectOne(isStyle);
    const sent = req.request.body.history as ConciergeHistoryEntry[];
    expect(sent).toHaveLength(12);
    expect(sent[0]).toEqual({ role: 'user', text: 'turn 2' });
    expect(sent[11].text).toHaveLength(500);
    for (const e of sent) {
      expect(Object.keys(e).sort()).toEqual(['role', 'text']);
    }
    req.flush({ interaction_id: 1, products: [] });
    await p;
  });

  it('parses reply, follow-ups (trimmed, de-duplicated) and the clarification flag', async () => {
    const { service, controller } = setup();
    const p = service.ask('a gift');
    controller.expectOne(isStyle).flush({
      interaction_id: 3,
      products: [],
      reply: ' Who is the gift for? ',
      follow_ups: [' For my sister ', 42, '', 'For my mum', 'For a friend', 'For my wife', 'For my mum'],
      needs_clarification: true,
    });
    const res = await p;
    expect(res.reply).toBe('Who is the gift for?');
    expect(res.followUps).toEqual(['For my sister', 'For my mum', 'For a friend', 'For my wife']);
    expect(res.needsClarification).toBe(true);
    expect(res.cards).toEqual([]);
    // An API without guided discovery: no slots, no question.
    expect(res.askedSlots).toEqual([]);
    expect(res.questionSlot).toBeNull();
  });

  it('always sends asked_slots (even [], absent = legacy client), omits show_now unless set', async () => {
    const { service, controller } = setup();
    const p1 = service.ask('abaya', [], { askedSlots: [], showNow: false });
    const r1 = controller.expectOne(isStyle);
    expect(r1.request.body.asked_slots).toEqual([]);
    expect('show_now' in r1.request.body).toBe(false);
    r1.flush({ interaction_id: 1, products: [] });
    await p1;

    const p2 = service.ask('Show me what you have', [], {
      askedSlots: ['recipient', 'bogus', 'occasion', 'recipient'],
      showNow: true,
    });
    const r2 = controller.expectOne(isStyle);
    expect(r2.request.body.asked_slots).toEqual(['recipient', 'occasion']);
    expect(r2.request.body.show_now).toBe(true);
    r2.flush({ interaction_id: 2, products: [] });
    await p2;
  });

  it('parses a guided question turn: question_slot, asked_slots and up to 8 answer chips', async () => {
    const { service, controller } = setup();
    const p = service.ask('an abaya');
    controller.expectOne(isStyle).flush({
      interaction_id: 4,
      products: [],
      reply: 'What\'s the occasion?',
      needs_clarification: true,
      asked_slots: ['recipient', 'occasion', 'nope'],
      question_slot: 'occasion',
      follow_ups: ['Eid', 'Wedding', 'Everyday', 'Work', 'Ramadan', 'Party/evening', 'No preference', 'Show me what you have', 'Extra'],
    });
    const res = await p;
    expect(res.questionSlot).toBe('occasion');
    expect(res.askedSlots).toEqual(['recipient', 'occasion']);
    expect(res.followUps).toHaveLength(8);
    expect(res.followUps.at(-1)).toBe('Show me what you have');
  });

  it('caps refine chips on a results turn at 6 and ignores an unknown question_slot', async () => {
    const { service, controller } = setup();
    const p = service.ask('abaya');
    controller.expectOne(isStyle).flush({
      interaction_id: 5,
      products: [],
      question_slot: 'weird',
      follow_ups: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
    });
    const res = await p;
    expect(res.questionSlot).toBeNull();
    expect(res.followUps).toEqual(['a', 'b', 'c', 'd', 'e', 'f']);
  });

  it('propagates HTTP errors so the thread can show Retry', async () => {
    const { service, controller } = setup();
    const p = service.ask('x');
    controller.expectOne(isStyle).flush('boom', { status: 500, statusText: 'Server Error' });
    await expect(p).rejects.toBeTruthy();
  });
});

describe('isShowNowChip', () => {
  it('recognises the skip phrase in English and Arabic (punctuation / diacritics ignored)', () => {
    expect(isShowNowChip('Show me what you have')).toBe(true);
    expect(isShowNowChip('  show me what you have! ')).toBe(true);
    expect(isShowNowChip('أريني ما لديكِ')).toBe(true);
    expect(isShowNowChip('Eid')).toBe(false);
    expect(isShowNowChip('No preference')).toBe(false);
    expect(isShowNowChip('Montrez-moi', 'Montrez-moi')).toBe(true);
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

describe('guided-discovery slots', () => {
  it('has six slots in the server order and asks up to 6 questions', () => {
    expect([...CONCIERGE_SLOTS]).toEqual(['recipient', 'occasion', 'type', 'colour', 'style', 'budget']);
    expect(CONCIERGE_MAX_QUESTIONS).toBe(6);
  });

  it('sanitizeSlots keeps known slots once, in order, max 6', () => {
    expect(sanitizeSlots(['budget', 'nope', 'colour', 'budget', 5])).toEqual(['budget', 'colour']);
    expect(sanitizeSlots([...CONCIERGE_SLOTS, ...CONCIERGE_SLOTS])).toEqual([...CONCIERGE_SLOTS]);
    expect(sanitizeSlots('recipient')).toEqual([]);
  });

  it('reads the legacy style_or_colour slot as both colour and style asked', () => {
    expect(sanitizeSlots(['recipient', 'style_or_colour'])).toEqual(['recipient', 'colour', 'style']);
    expect(sanitizeSlots(['style', 'style_or_colour'])).toEqual(['style', 'colour']);
    expect(toQuestionSlot('style_or_colour')).toBe('colour');
    expect(toQuestionSlot('style')).toBe('style');
    expect(toQuestionSlot('weird')).toBeNull();
  });

  it('parses a colour question with the legacy slot from an older API', async () => {
    const { service, controller } = setup();
    const p = service.ask('an abaya', [], { askedSlots: ['recipient'] });
    const req = controller.expectOne(isStyle);
    expect(req.request.body.asked_slots).toEqual(['recipient']);
    req.flush({ interaction_id: 9, products: [], asked_slots: ['recipient', 'style_or_colour'], question_slot: 'style_or_colour', follow_ups: ['Black'] });
    const res = await p;
    expect(res.questionSlot).toBe('colour');
    expect(res.askedSlots).toEqual(['recipient', 'colour', 'style']);
    // Two questions asked (the legacy combined one counts once).
    expect(res.questionCount).toBe(2);
    TestBed.resetTestingModule();
  });

  it('countAskedQuestions counts the legacy combined slot once, ignores junk, caps at 6', () => {
    expect(countAskedQuestions(['recipient', 'style_or_colour'])).toBe(2);
    expect(countAskedQuestions(['recipient', 'colour', 'style'])).toBe(3);
    expect(countAskedQuestions(['style', 'style_or_colour'])).toBe(1);
    expect(countAskedQuestions(['recipient', 'bogus', 7, 'recipient'])).toBe(1);
    expect(countAskedQuestions([...CONCIERGE_SLOTS, 'style_or_colour'])).toBe(6);
    expect(countAskedQuestions('recipient')).toBe(0);
  });
});
