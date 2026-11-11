import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { RouterTestingHarness } from '@angular/router/testing';
import { Router, provideRouter } from '@angular/router';
import { PLATFORM_ID, signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';

import { AskAinPageComponent } from './ask-ain-page';
import { AskAinThreadService } from './ask-ain-thread.service';
import { ConciergeService, type ConciergeCard, type ConciergeResult } from './concierge.service';
import { WishlistService } from '../wishlist/wishlist.service';
import { AuthService } from '../../core/auth/auth.service';
import { provideI18n } from '../../core/i18n';

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
  return { interactionId: 11, cards: [], reply: '', followUps: [], needsClarification: false, ...o };
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

describe('AskAinPageComponent (chat thread)', () => {
  beforeEach(() => {
    sessionStorage.clear();
  });
  afterEach(() => {
    TestBed.resetTestingModule();
    sessionStorage.clear();
    vi.restoreAllMocks();
  });

  it('shows the greeting suggestion chips on a fresh thread, a quiet log and one polite announcer', async () => {
    const { el, recordEvent } = await setup(vi.fn());
    expect(qa(el, 'ask-ain-suggestion')).toHaveLength(3);
    const log = q(el, 'ask-ain-thread')!;
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

  it('a suggestion chip starts the thread: user bubble, typing, then Ain reply + cards + follow-ups', async () => {
    let resolve!: (r: ConciergeResult) => void;
    const ask = vi.fn(() => new Promise<ConciergeResult>((r) => (resolve = r)));
    const { harness, el } = await setup(ask);

    qa(el, 'ask-ain-suggestion')[0].click();
    harness.detectChanges();
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(1);
    expect(q(el, 'ask-ain-typing')).not.toBeNull();
    expect(qa(el, 'ask-ain-suggestion')).toHaveLength(0);
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
    ]);
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

    expect(ask).toHaveBeenCalledWith('hello', []);
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
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(1);
    q<HTMLButtonElement>(el, 'ask-ain-retry')!.click();
    await settle(harness);

    expect(ask).toHaveBeenCalledTimes(2);
    expect(q(el, 'ask-ain-error')).toBeNull();
    expect(q(el, 'ask-ain-reply')!.textContent).toContain('Back with picks.');
  });

  it('New chat resets to the fresh greeting state', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Hello!' })));
    const { harness, el } = await setup(ask);
    await TestBed.inject(AskAinThreadService).send('hi');
    await settle(harness);

    q<HTMLButtonElement>(el, 'ask-ain-new-chat')!.click();
    harness.detectChanges();
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(0);
    expect(qa(el, 'ask-ain-suggestion')).toHaveLength(3);
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
    expect(qa(el, 'ask-ain-suggestion')).toHaveLength(0);
  });

  it('?q= starts a new conversation and drops the param from the URL', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Lovely choice.' })));
    const { harness, el } = await setup(ask, '/ask-ain?q=An%20Eid%20gift');
    await settle(harness);
    expect(ask).toHaveBeenCalledWith('An Eid gift', []);
    expect(qa(el, 'ask-ain-user-turn')).toHaveLength(1);
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/ask-ain');
  });
});
