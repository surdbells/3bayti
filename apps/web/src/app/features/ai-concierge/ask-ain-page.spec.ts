import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { TestBed } from '@angular/core/testing';
import { RouterTestingHarness } from '@angular/router/testing';
import { Router, provideRouter } from '@angular/router';
import { PLATFORM_ID, signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { TranslateService } from '@ngx-translate/core';

import { AskAinPageComponent } from './ask-ain-page';
import { AskAinThreadService } from './ask-ain-thread.service';
import { ConciergeService, type ConciergeCard, type ConciergeResult } from './concierge.service';
import { WishlistService } from '../wishlist/wishlist.service';
import { AuthService } from '../../core/auth/auth.service';
import { provideI18n } from '../../core/i18n';

/** The guided-discovery options of a send with no prior questions. */
const NO_SLOTS = { askedSlots: [], showNow: false };

function card(id: number): ConciergeCard {
  return {
    id,
    slug: `p-${id}`,
    name: `Piece ${id}`,
    price: { amount: '100.00', currency: 'AED' },
    sale_price: null,
    primary_image: null,
    in_stock: true,
    reason: 'Fits the brief',
  } as unknown as ConciergeCard;
}

function result(o: Partial<ConciergeResult> = {}): ConciergeResult {
  return { interactionId: 11, cards: [], reply: '', followUps: [], needsClarification: false, askedSlots: [], questionSlot: null, ...o };
}

interface Ctx {
  harness: RouterTestingHarness;
  el: HTMLElement;
  ask: ReturnType<typeof vi.fn>;
  recordEvent: ReturnType<typeof vi.fn>;
}

async function setup(ask: ReturnType<typeof vi.fn>, url = '/ask-ain'): Promise<Ctx> {
  const recordEvent = vi.fn();
  TestBed.configureTestingModule({
    providers: [
      provideRouter([{ path: 'ask-ain', component: AskAinPageComponent }]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ConciergeService, useValue: { ask, recordEvent } },
      { provide: WishlistService, useValue: { isSaved: () => false, toggle: vi.fn(() => Promise.resolve()) } },
      { provide: AuthService, useValue: { isAuthenticated: signal(false).asReadonly(), currentUser: signal(null).asReadonly() } },
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });
  const harness = await RouterTestingHarness.create();
  await harness.navigateByUrl(url, AskAinPageComponent);
  return { harness, el: harness.routeNativeElement as HTMLElement, ask, recordEvent };
}

/** Let the stubbed ask() promise settle and re-render. */
async function settle(h: RouterTestingHarness): Promise<void> {
  for (let i = 0; i < 4; i++) {
    await Promise.resolve();
  }
  h.detectChanges();
  await h.fixture.whenStable();
  h.detectChanges();
}

const q = <T extends Element = HTMLElement>(el: HTMLElement, id: string) => el.querySelector<T>(`[data-testid="${id}"]`);
const qa = (el: HTMLElement, id: string) => Array.from(el.querySelectorAll<HTMLElement>(`[data-testid="${id}"]`));

/** Type into the composer and submit it. */
function typeAndSend(h: RouterTestingHarness, el: HTMLElement, text: string): void {
  const input = el.querySelector<HTMLInputElement>('#ask-ain-input')!;
  input.value = text;
  input.dispatchEvent(new Event('input'));
  h.detectChanges();
  q<HTMLFormElement>(el, 'ask-ain-composer')!.dispatchEvent(new Event('submit'));
  h.detectChanges();
}

/** The data-testids of the thread's direct children, in order. */
const threadOrder = (el: HTMLElement): string[] =>
  Array.from(q(el, 'ask-ain-thread')!.children).map((c) => c.getAttribute('data-testid') ?? '');

describe('AskAinPageComponent (chat thread)', () => {
  beforeEach(() => {
    sessionStorage.clear();
  });
  afterEach(() => {
    TestBed.resetTestingModule();
    sessionStorage.clear();
    vi.restoreAllMocks();
  });

  it('a fresh thread starts with Ain\'s opening bubble in the log: no chips, no API call, a quiet log and one polite announcer', async () => {
    const ask = vi.fn();
    const { el, recordEvent } = await setup(ask);
    const log = q(el, 'ask-ain-thread')!;
    // The conversation itself starts with Ain, inside the thread.
    expect(threadOrder(el)).toEqual(['ask-ain-opening']);
    expect(log.contains(q(el, 'ask-ain-opening-text'))).toBe(true);
    // No preselects: no starter prompts, no chips of any kind, nothing sent.
    expect(q(el, 'ask-ain-starters')).toBeNull();
    expect(qa(el, 'ask-ain-suggestion')).toHaveLength(0);
    expect(qa(el, 'ask-ain-chips')).toHaveLength(0);
    expect(el.querySelectorAll('button.ask-ain__chip')).toHaveLength(0);
    expect(ask).not.toHaveBeenCalled();
    expect(log.getAttribute('role')).toBe('log');
    // The log itself is not live: a whole turn (with every product card) must not be read out.
    expect(log.getAttribute('aria-live')).toBe('off');
    expect(log.hasAttribute('aria-busy')).toBe(false);
    const announcer = q(el, 'ask-ain-announcer')!;
    expect(announcer.getAttribute('aria-live')).toBe('polite');
    expect(announcer.textContent!.trim()).toBe('');
    expect(q(el, 'ask-ain-new-chat')).toBeNull();
    expect(recordEvent).toHaveBeenCalledWith('ai_opened');
  });

  it('the first message continues the thread: opening, user bubble, typing, then Ain reply + cards + follow-ups', async () => {
    let resolve!: (r: ConciergeResult) => void;
    const ask = vi.fn(() => new Promise<ConciergeResult>((r) => (resolve = r)));
    const { harness, el } = await setup(ask);

    typeAndSend(harness, el, 'an elegant abaya');
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(1);
    expect(q(el, 'ask-ain-typing')).not.toBeNull();
    // The typing indicator renders inside the thread, after the user's message.
    expect(threadOrder(el)).toEqual(['ask-ain-opening', 'ask-ain-user-turn', 'ask-ain-typing']);
    // The local opening bubble is never sent as history.
    expect(ask).toHaveBeenCalledWith('an elegant abaya', [], NO_SLOTS);
    // While waiting, only the short typing line is announced (i18n is not
    // loaded in tests, so assert the key the announcer renders).
    const page = harness.routeDebugElement!.componentInstance as AskAinPageComponent;
    expect(page.announcement()).toEqual({ kind: 'key', text: 'askAin.typing' });

    resolve(result({ reply: 'Here are elegant pieces.', cards: [card(1), card(2)], followUps: ['In black', 'Cheaper'] }));
    await settle(harness);

    expect(q(el, 'ask-ain-typing')).toBeNull();
    expect(q(el, 'ask-ain-reply')!.textContent).toContain('Here are elegant pieces.');
    // The announcer carries only Ain's reply: no product names, no customer text.
    const announced = q(el, 'ask-ain-announcer')!.textContent!.trim();
    expect(announced).toBe('Here are elegant pieces.');
    expect(el.querySelectorAll('ui-product-card')).toHaveLength(2);
    expect(qa(el, 'ask-ain-follow-up').map((b) => b.textContent!.trim())).toEqual(['In black', 'Cheaper']);
    expect(q(el, 'ask-ain-new-chat')).not.toBeNull();
    // Reply, product cards and refine chips all live inside the thread.
    const log = q(el, 'ask-ain-thread')!;
    expect(log.querySelectorAll('ui-product-card')).toHaveLength(2);
    expect(log.contains(q(el, 'ask-ain-chips'))).toBe(true);
  });

  it('tapping a follow-up chip sends its text with the conversation as history', async () => {
    const ask = vi
      .fn()
      .mockResolvedValueOnce(result({ reply: 'Found these.', cards: [card(1)], followUps: ['Show me cheaper ones'] }))
      .mockResolvedValueOnce(result({ reply: 'Cheaper picks.', cards: [card(2)] }));
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('black abaya');
    await settle(harness);

    qa(el, 'ask-ain-follow-up')[0].click();
    await settle(harness);

    expect(ask).toHaveBeenLastCalledWith('Show me cheaper ones', [
      { role: 'user', text: 'black abaya' },
      { role: 'ain', text: 'Found these.' },
    ], NO_SLOTS);
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(2);
    expect(qa(el, 'ask-ain-ain-turn')).toHaveLength(2);
    // Follow-ups are offered only on the latest Ain turn.
    expect(qa(el, 'ask-ain-follow-up')).toHaveLength(0);
  });

  it('the composer sends, clears and keeps focus', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Hi there!' })));
    const { harness, el } = await setup(ask);
    const input = el.querySelector<HTMLInputElement>('#ask-ain-input')!;
    input.focus();
    input.value = 'hello';
    input.dispatchEvent(new Event('input'));
    harness.detectChanges();
    q<HTMLFormElement>(el, 'ask-ain-composer')!.dispatchEvent(new Event('submit'));
    await settle(harness);

    expect(ask).toHaveBeenCalledWith('hello', [], NO_SLOTS);
    expect(input.value).toBe('');
    expect(input.disabled).toBe(false);
    expect(document.activeElement).toBe(input);
    expect(q(el, 'ask-ain-reply')!.textContent).toContain('Hi there!');
  });

  it('renders a clarification turn as a question with likely answers and no product grid', async () => {
    const ask = vi.fn(() =>
      Promise.resolve(result({ reply: 'Who is the gift for?', needsClarification: true, followUps: ['For my sister'] })),
    );
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('a gift');
    await settle(harness);
    expect(q(el, 'ask-ain-reply')!.textContent).toContain('Who is the gift for?');
    expect(el.querySelectorAll('ui-product-card')).toHaveLength(0);
    expect(qa(el, 'ask-ain-follow-up')).toHaveLength(1);
  });

  it('shows an error bubble with Retry, keeps the thread, and Retry recovers', async () => {
    const ask = vi
      .fn()
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce(result({ reply: 'Back with picks.', cards: [card(3)] }));
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('abaya');
    await settle(harness);

    expect(q(el, 'ask-ain-error')).not.toBeNull();
    expect(q(el, 'ask-ain-thread')!.contains(q(el, 'ask-ain-retry'))).toBe(true);
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(1);
    q<HTMLButtonElement>(el, 'ask-ain-retry')!.click();
    await settle(harness);

    expect(ask).toHaveBeenCalledTimes(2);
    expect(q(el, 'ask-ain-error')).toBeNull();
    expect(q(el, 'ask-ain-reply')!.textContent).toContain('Back with picks.');
  });

  it('New chat resets to just the opening bubble', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Hello!' })));
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('hi');
    await settle(harness);

    q<HTMLButtonElement>(el, 'ask-ain-new-chat')!.click();
    harness.detectChanges();
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(0);
    expect(threadOrder(el)).toEqual(['ask-ain-opening']);
    expect(qa(el, 'ask-ain-chips')).toHaveLength(0);
    expect(q(el, 'ask-ain-new-chat')).toBeNull();
    expect(sessionStorage.getItem(AskAinThreadService.STORAGE_KEY)).toBeNull();
  });

  it('beacons product clicks with that turn\'s interaction id', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ interactionId: 42, reply: 'Pieces.', cards: [card(7)] })));
    const { harness, el, recordEvent } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('abaya');
    await settle(harness);
    el.querySelector<HTMLElement>('.ask-ain__cell')!.dispatchEvent(new Event('click'));
    expect(recordEvent).toHaveBeenCalledWith('ai_product_clicked', { interaction_id: 42, product_id: 7 });
  });

  it('restores a persisted thread when the page is opened again', async () => {
    sessionStorage.setItem(
      AskAinThreadService.STORAGE_KEY,
      JSON.stringify([
        { id: 'u1', role: 'user', text: 'abaya' },
        { id: 'a1', role: 'ain', status: 'ok', reply: 'Pieces for you.', cards: [card(4)], followUps: ['In black'], needsClarification: false, interactionId: 5 },
      ]),
    );
    const { el } = await setup(vi.fn());
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(1);
    expect(q(el, 'ask-ain-reply')!.textContent).toContain('Pieces for you.');
    expect(el.querySelectorAll('ui-product-card')).toHaveLength(1);
    // The opening bubble stays first; the restored turns follow it.
    expect(threadOrder(el)).toEqual(['ask-ain-opening', 'ask-ain-user-turn', 'ask-ain-ain-turn']);
  });

  it('restores an older thread whose asked_slots used the combined style_or_colour slot', async () => {
    sessionStorage.setItem(
      AskAinThreadService.STORAGE_KEY,
      JSON.stringify([
        { id: 'u1', role: 'user', text: 'abaya' },
        { id: 'a1', role: 'ain', status: 'ok', reply: 'Any style or colour?', cards: [], followUps: ['Black'], needsClarification: true, interactionId: 5, askedSlots: ['recipient', 'style_or_colour'], questionSlot: 'style_or_colour' },
      ]),
    );
    const ask = vi.fn().mockResolvedValue(result({ reply: 'Ok.' }));
    const { harness, el } = await setup(ask);
    expect(q(el, 'ask-ain-progress')).not.toBeNull();
    qa(el, 'ask-ain-follow-up')[0].click();
    await settle(harness);
    expect(ask.mock.calls[0][2]).toEqual({ askedSlots: ['recipient', 'colour', 'style'], showNow: false });
  });

  it('?q= starts a new conversation and drops the param from the URL', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Lovely choice.' })));
    const { harness, el } = await setup(ask, '/ask-ain?q=An%20Eid%20gift');
    await settle(harness);
    expect(ask).toHaveBeenCalledWith('An Eid gift', [], NO_SLOTS);
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(1);
    // The opening bubble, then the deep-linked message, then Ain's answer.
    expect(threadOrder(el)).toEqual(['ask-ain-opening', 'ask-ain-user-turn', 'ask-ain-ain-turn']);
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/ask-ain');
  });
});

describe('AskAinPageComponent (guided discovery)', () => {
  const EN = {
    askAin: {
      showNow: 'Show me what you have',
      progress: 'Question {{current}} of up to {{total}}',
      newChat: 'New chat',
      ain: 'Ain',
      opening: 'Hi, I\'m Ain, your personal stylist at 3bayti.',
      searchByPhoto: 'Search by a photo',
    },
  };

  function useEnglish(): void {
    // Answer any pending /i18n/*.json load so it can't later blank the strings.
    const http = TestBed.inject(HttpTestingController);
    for (const r of http.match((req) => req.url.includes('/i18n/'))) {
      r.flush(EN);
    }
    const t = TestBed.inject(TranslateService);
    t.setTranslation('en', EN, true);
    t.use('en');
  }

  beforeEach(() => {
    sessionStorage.clear();
  });
  afterEach(() => {
    TestBed.resetTestingModule();
    sessionStorage.clear();
    vi.restoreAllMocks();
  });

  it('counts a restored legacy style_or_colour slot as ONE question in the progress hint', async () => {
    sessionStorage.setItem(
      AskAinThreadService.STORAGE_KEY,
      JSON.stringify([
        { id: 'u1', role: 'user', text: 'abaya' },
        { id: 'a1', role: 'ain', status: 'ok', reply: 'Any style or colour?', cards: [], followUps: ['Black'], needsClarification: true, interactionId: 5, askedSlots: ['recipient', 'style_or_colour'], questionSlot: 'style_or_colour' },
      ]),
    );
    const { harness, el } = await setup(vi.fn());
    useEnglish();
    harness.detectChanges();
    // Two questions were asked (recipient + the combined one), not three.
    expect(q(el, 'ask-ain-progress')!.textContent!.trim()).toBe('Question 2 of up to 6');
  });

  function question(o: Partial<ConciergeResult> = {}): ConciergeResult {
    return result({
      reply: 'What\'s the occasion?',
      needsClarification: true,
      askedSlots: ['recipient', 'occasion'],
      questionSlot: 'occasion',
      followUps: ['Show me what you have', 'Eid', 'Wedding', 'Everyday', 'Work', 'Ramadan', 'Party/evening', 'No preference'],
      ...o,
    });
  }

  it('the opening bubble is localized, offers only inline links (no chips), and nothing sits outside the thread but the header and composer', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Lovely.' })));
    const { harness, el } = await setup(ask);
    useEnglish();
    harness.detectChanges();

    expect(q(el, 'ask-ain-opening-text')!.textContent!.replace(/\s+/g, ' ').trim()).toBe('Ain: Hi, I\'m Ain, your personal stylist at 3bayti.');
    const opening = q(el, 'ask-ain-opening')!;
    expect(opening.querySelectorAll('button')).toHaveLength(0);
    // No preselects: only the optional WhatsApp entry sits in the opening bubble.
    expect(q(el, 'ask-ain-photo')).toBeNull();
    // AIN_WHATSAPP_NUMBER is a build-time constant, so the link always renders:
    // assert it unconditionally as an inline text link inside the bubble.
    const wa = q(el, 'ask-ain-whatsapp');
    expect(wa).not.toBeNull();
    expect(opening.contains(wa)).toBe(true);
    expect(wa!.tagName).toBe('A');
    expect(wa!.classList.contains('ask-ain__chip')).toBe(false);

    // The page is: header, the (visually hidden) announcer, the thread, the composer.
    const section = el.querySelector('section.ask-ain')!;
    const kids = Array.from(section.children).map(
      (c) => c.tagName.toLowerCase() + (c.getAttribute('data-testid') ? '#' + c.getAttribute('data-testid') : ''),
    );
    expect(kids).toEqual(['header', 'p#ask-ain-announcer', 'div#ask-ain-thread', 'form#ask-ain-composer']);
    expect(ask).not.toHaveBeenCalled();
  });

  it('renders a question turn: progress hint, wrapping answer chips, skip chip last and distinct, no products', async () => {
    const ask = vi.fn(() => Promise.resolve(question()));
    const { harness, el } = await setup(ask);
    useEnglish();
    await TestBed.inject(AskAinThreadService).send('an abaya');
    await settle(harness);

    expect(q(el, 'ask-ain-progress')!.textContent!.trim()).toBe('Question 2 of up to 6');
    expect(q(el, 'ask-ain-reply')!.textContent).toContain('What\'s the occasion?');
    expect(q(el, 'ask-ain-reply')!.classList).toContain('ask-ain__bubble--question');
    expect(el.querySelectorAll('ui-product-card')).toHaveLength(0);

    const group = q(el, 'ask-ain-chips')!;
    expect(group.classList).toContain('ask-ain__followups--answers');
    expect(qa(el, 'ask-ain-follow-up').map((b) => b.textContent!.trim())).toEqual([
      'Eid', 'Wedding', 'Everyday', 'Work', 'Ramadan', 'Party/evening', 'No preference',
    ]);
    const skip = q(el, 'ask-ain-show-now')!;
    expect(skip.classList).toContain('ask-ain__chip--skip');
    expect(group.lastElementChild).toBe(skip);
  });

  it('answer chips echo asked_slots; the skip chip also sends show_now', async () => {
    const ask = vi
      .fn()
      .mockResolvedValueOnce(question())
      .mockResolvedValueOnce(question({ askedSlots: ['recipient', 'occasion', 'type'], questionSlot: 'type', followUps: ['Abaya', 'No preference'] }))
      .mockResolvedValueOnce(result({ reply: 'Here you go.', cards: [card(1)], askedSlots: ['recipient', 'occasion', 'type'] }));
    const { harness, el } = await setup(ask);
    useEnglish();
    await TestBed.inject(AskAinThreadService).send('an abaya');
    await settle(harness);

    qa(el, 'ask-ain-follow-up')[0].click(); // "Eid"
    await settle(harness);
    expect(ask.mock.calls[1][0]).toBe('Eid');
    expect(ask.mock.calls[1][2]).toEqual({ askedSlots: ['recipient', 'occasion'], showNow: false });
    expect(q(el, 'ask-ain-progress')!.textContent!.trim()).toBe('Question 3 of up to 6');
    // The server sent no skip chip this time: the page adds its own.
    q<HTMLButtonElement>(el, 'ask-ain-show-now')!.click();
    await settle(harness);
    expect(ask.mock.calls[2][0]).toBe('Show me what you have');
    expect(ask.mock.calls[2][2]).toEqual({ askedSlots: ['recipient', 'occasion', 'type'], showNow: true });
    expect(q(el, 'ask-ain-progress')).toBeNull();
    expect(el.querySelectorAll('ui-product-card')).toHaveLength(1);
  });

  it('only the newest Ain turn has active chips', async () => {
    const ask = vi.fn().mockResolvedValueOnce(question()).mockResolvedValueOnce(question({ askedSlots: ['recipient', 'occasion', 'type'], questionSlot: 'type' }));
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('an abaya');
    await settle(harness);
    qa(el, 'ask-ain-follow-up')[1].click();
    await settle(harness);
    expect(qa(el, 'ask-ain-chips')).toHaveLength(1);
    expect(qa(el, 'ask-ain-show-now')).toHaveLength(1);
  });

  it('results turns show up to 6 refine chips under the products', async () => {
    const ask = vi.fn(() =>
      Promise.resolve(
        result({
          reply: 'Here are some pieces.',
          cards: [card(1), card(2)],
          followUps: ['In black', 'Something more colourful', 'Under 300 AED', 'More embellished', 'For a wedding instead', 'Similar but cheaper'],
        }),
      ),
    );
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('abaya');
    await settle(harness);
    expect(qa(el, 'ask-ain-follow-up')).toHaveLength(6);
    expect(q(el, 'ask-ain-show-now')).toBeNull();
    expect(q(el, 'ask-ain-progress')).toBeNull();
    expect(q(el, 'ask-ain-chips')!.classList).not.toContain('ask-ain__followups--answers');
    // Chips come after the product grid.
    const grid = el.querySelector('.ask-ain__grid')!;
    expect(grid.compareDocumentPosition(q(el, 'ask-ain-chips')!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('New chat resets asked_slots for the next message', async () => {
    const ask = vi.fn().mockResolvedValueOnce(question()).mockResolvedValueOnce(result({ reply: 'Hi!' }));
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('an abaya');
    await settle(harness);
    q<HTMLButtonElement>(el, 'ask-ain-new-chat')!.click();
    harness.detectChanges();
    typeAndSend(harness, el, 'hello');
    await settle(harness);
    expect(ask.mock.calls[1][2]).toEqual(NO_SLOTS);
  });

  it('New chat button: one-line label, icon + label, accessible name kept', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Hello!' })));
    const { harness, el } = await setup(ask);
    useEnglish();
    await TestBed.inject(AskAinThreadService).send('hi');
    await settle(harness);

    const btn = q<HTMLButtonElement>(el, 'ask-ain-new-chat')!;
    expect(btn.getAttribute('aria-label')).toBe('New chat');
    expect(btn.querySelector('svg')!.getAttribute('aria-hidden')).toBe('true');
    expect(q(el, 'ask-ain-new-chat-label')!.textContent!.trim()).toBe('New chat');
    // Class contract the no-wrap stylesheet rules hang off.
    expect(btn.classList.contains('ask-ain__new')).toBe(true);
    expect(btn.querySelector('svg')!.classList.contains('ask-ain__new-icon')).toBe(true);
    expect(q(el, 'ask-ain-new-chat-label')!.classList.contains('ask-ain__new-label')).toBe(true);
  });

  it('New chat button stylesheet: never squashes or wraps, icon-only at <=360px', () => {
    // vitest stubs component SCSS (no computed styles in jsdom), so assert the
    // source rules directly.
    const rel = 'src/app/features/ai-concierge/ask-ain-page.scss';
    const file = [resolve(process.cwd(), rel), resolve(process.cwd(), 'apps/web', rel)].find((f) => existsSync(f));
    expect(file, 'ask-ain-page.scss located').toBeTruthy();
    const scss = readFileSync(file!, 'utf8');
    const rule = (selector: string, source = scss): string => {
      const re = new RegExp(`(?:^|\\n)\\s*${selector.replace(/[.]/g, '\\.')}\\s*\\{([^}]*)\\}`);
      const m = source.match(re);
      expect(m, `rule ${selector}`).not.toBeNull();
      return m![1];
    };

    const btn = rule('.ask-ain__new');
    expect(btn).toMatch(/flex:\s*0 0 auto/);
    expect(btn).toMatch(/display:\s*inline-flex/);
    expect(btn).toMatch(/white-space:\s*nowrap/);
    expect(btn).toMatch(/height:\s*2\.5rem/);
    expect(btn).toMatch(/line-height:\s*1\b/);
    expect(rule('.ask-ain__new-label')).toMatch(/white-space:\s*nowrap/);
    expect(rule('.ask-ain__new-icon')).toMatch(/flex:\s*0 0 auto/);

    const narrowAt = scss.indexOf('@media (max-width: 360px)');
    expect(narrowAt).toBeGreaterThan(-1);
    const narrow = scss.slice(narrowAt);
    expect(rule('.ask-ain__new-label', narrow)).toMatch(/display:\s*none/);

    // The pinned composer is centred like the header and thread (same width,
    // auto side margins), so it lines up on wide screens.
    const form = rule('.ask-ain__form');
    expect(form).toMatch(/max-width:\s*64rem/);
    expect(form).toMatch(/margin:\s*0 auto\b/);
    expect(rule('.ask-ain__head')).toMatch(/margin:\s*0 auto\b/);
    expect(rule('.ask-ain__thread')).toMatch(/margin:\s*0 auto\b/);
  });
});
