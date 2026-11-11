import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { PLATFORM_ID, signal } from '@angular/core';

import { AuthService } from '../../core/auth/auth.service';
import type { AuthUser } from '../../core/auth/auth.types';

import { AskAinThreadService, buildHistory, type ChatTurn } from './ask-ain-thread.service';
import { ConciergeService, type ConciergeCard, type ConciergeResult } from './concierge.service';

function card(id: number): ConciergeCard {
  return {
    id,
    slug: `p-${id}`,
    name: `Piece ${id}`,
    price: { amount: '100.00', currency: 'AED' },
    sale_price: null,
    primary_image: null,
    reason: 'Fits the brief',
  } as unknown as ConciergeCard;
}

function result(o: Partial<ConciergeResult> = {}): ConciergeResult {
  return { interactionId: 1, cards: [], reply: '', followUps: [], needsClarification: false, ...o };
}

interface Deferred<T> {
  promise: Promise<T>;
  resolve: (v: T) => void;
  reject: (e: unknown) => void;
}
function deferred<T>(): Deferred<T> {
  let resolve!: (v: T) => void;
  let reject!: (e: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

/** Writable stand-in for AuthService.currentUser (sign-in / sign-out). */
let currentUser = signal<AuthUser | null>(null);
function user(id: number): AuthUser {
  return { id } as unknown as AuthUser;
}

function setup(ask: ReturnType<typeof vi.fn>): AskAinThreadService {
  TestBed.configureTestingModule({
    providers: [
      { provide: ConciergeService, useValue: { ask, recordEvent: vi.fn() } },
      { provide: AuthService, useValue: { currentUser: currentUser.asReadonly() } },
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });
  return TestBed.inject(AskAinThreadService);
}

describe('AskAinThreadService', () => {
  beforeEach(() => {
    sessionStorage.clear();
    currentUser = signal<AuthUser | null>(null);
  });
  afterEach(() => {
    TestBed.resetTestingModule();
    sessionStorage.clear();
    vi.restoreAllMocks();
  });

  it('starts fresh, shows pending while waiting, then appends user + Ain turns', async () => {
    const d = deferred<ConciergeResult>();
    const ask = vi.fn(() => d.promise);
    const thread = setup(ask);
    expect(thread.isFresh()).toBe(true);

    const p = thread.send('  an abaya for a wedding  ');
    expect(thread.pending()).toBe(true);
    expect(thread.isFresh()).toBe(false);
    expect(thread.turns()).toHaveLength(1);
    expect(thread.turns()[0]).toMatchObject({ role: 'user', text: 'an abaya for a wedding' });
    expect(ask).toHaveBeenCalledWith('an abaya for a wedding', []);

    d.resolve(result({ reply: 'Here are elegant pieces.', cards: [card(1), card(2)], followUps: ['In black'] }));
    await p;
    expect(thread.pending()).toBe(false);
    const ain = thread.turns()[1];
    expect(ain).toMatchObject({ role: 'ain', status: 'ok', reply: 'Here are elegant pieces.', followUps: ['In black'] });
    expect(ain.role === 'ain' && ain.cards.length).toBe(2);
  });

  it('sends the prior conversation as text-only history on a follow-up', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Here are some pieces.', cards: [card(1)] })));
    const thread = setup(ask);
    await thread.send('black abaya');
    await thread.send('Show me cheaper ones');
    expect(ask).toHaveBeenLastCalledWith('Show me cheaper ones', [
      { role: 'user', text: 'black abaya' },
      { role: 'ain', text: 'Here are some pieces.' },
    ]);
  });

  it('ignores a send while a turn is in flight and empty messages', async () => {
    const d = deferred<ConciergeResult>();
    const ask = vi.fn(() => d.promise);
    const thread = setup(ask);
    const p = thread.send('one');
    await thread.send('two');
    await thread.send('   ');
    expect(ask).toHaveBeenCalledTimes(1);
    d.resolve(result());
    await p;
    expect(thread.turns()).toHaveLength(2);
  });

  it('records a clarification turn with no products and its follow-ups', async () => {
    const ask = vi.fn(() =>
      Promise.resolve(
        result({ reply: 'Who is the gift for?', needsClarification: true, followUps: ['For my sister', 'For my mum'] }),
      ),
    );
    const thread = setup(ask);
    await thread.send('a gift');
    const ain = thread.turns()[1];
    expect(ain).toMatchObject({ role: 'ain', needsClarification: true, cards: [], followUps: ['For my sister', 'For my mum'] });

    // Answering via a follow-up keeps the clarifying question in history.
    await thread.send('For my sister');
    expect(ask).toHaveBeenLastCalledWith('For my sister', [
      { role: 'user', text: 'a gift' },
      { role: 'ain', text: 'Who is the gift for?' },
    ]);
  });

  it('turns a network failure into an error turn, keeps the thread, and Retry re-sends', async () => {
    const ask = vi
      .fn()
      .mockResolvedValueOnce(result({ reply: 'Found these.', cards: [card(1)] }))
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce(result({ reply: 'Cheaper picks.', cards: [card(2)] }));
    const thread = setup(ask);
    await thread.send('abaya');
    await thread.send('cheaper');

    const turns = thread.turns();
    expect(turns).toHaveLength(4);
    expect(turns[3]).toMatchObject({ role: 'ain', status: 'error' });
    expect(thread.pending()).toBe(false);

    await thread.retry();
    expect(ask).toHaveBeenCalledTimes(3);
    expect(ask).toHaveBeenLastCalledWith('cheaper', [
      { role: 'user', text: 'abaya' },
      { role: 'ain', text: 'Found these.' },
    ]);
    const after = thread.turns();
    expect(after).toHaveLength(4);
    expect(after.filter((t) => t.role === 'user')).toHaveLength(2);
    expect(after[3]).toMatchObject({ role: 'ain', status: 'ok', reply: 'Cheaper picks.' });
  });

  it('retry is a no-op unless the last turn is an error', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Hi!' })));
    const thread = setup(ask);
    await thread.send('hi');
    await thread.retry();
    expect(ask).toHaveBeenCalledTimes(1);
  });

  it('New chat clears the thread, storage and drops a late answer', async () => {
    const d = deferred<ConciergeResult>();
    const ask = vi.fn().mockResolvedValueOnce(result({ reply: 'Hello' })).mockReturnValueOnce(d.promise);
    const thread = setup(ask);
    await thread.send('hi');
    expect(sessionStorage.getItem(AskAinThreadService.STORAGE_KEY)).not.toBeNull();

    const p = thread.send('abaya');
    thread.newChat();
    expect(thread.turns()).toEqual([]);
    expect(thread.pending()).toBe(false);
    expect(thread.isFresh()).toBe(true);
    expect(sessionStorage.getItem(AskAinThreadService.STORAGE_KEY)).toBeNull();

    d.resolve(result({ reply: 'Too late', cards: [card(9)] }));
    await p;
    expect(thread.turns()).toEqual([]);
  });

  it('persists to sessionStorage and restores the thread (with cards) in a new instance', async () => {
    const ask = vi.fn(() => Promise.resolve(result({ reply: 'Pieces for you.', cards: [card(5)], followUps: ['In black'] })));
    setup(ask);
    await TestBed.inject(AskAinThreadService).send('abaya');
    TestBed.resetTestingModule();

    const restored = setup(vi.fn());
    const turns = restored.turns();
    expect(turns).toHaveLength(2);
    expect(turns[0]).toMatchObject({ role: 'user', text: 'abaya' });
    expect(turns[1]).toMatchObject({ role: 'ain', reply: 'Pieces for you.', followUps: ['In black'] });
    expect(turns[1].role === 'ain' && turns[1].cards[0].slug).toBe('p-5');
  });

  it('restores an unanswered question (reload mid-request) with a Retry error turn', () => {
    sessionStorage.setItem(AskAinThreadService.STORAGE_KEY, JSON.stringify([{ id: 'a', role: 'user', text: 'abaya' }]));
    const thread = setup(vi.fn());
    expect(thread.turns()).toHaveLength(2);
    expect(thread.turns()[1]).toMatchObject({ role: 'ain', status: 'error' });
  });

  it('starts fresh on corrupt storage or when storage throws', () => {
    sessionStorage.setItem(AskAinThreadService.STORAGE_KEY, '{not json');
    expect(setup(vi.fn()).turns()).toEqual([]);
    TestBed.resetTestingModule();

    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const thread = setup(vi.fn(() => Promise.resolve(result({ reply: 'ok' }))));
    expect(thread.turns()).toEqual([]);
    return thread.send('hi').then(() => expect(thread.turns()).toHaveLength(2));
  });

  it('does not send an unanswered (failed) message as history when the customer moves on', async () => {
    const ask = vi
      .fn()
      .mockResolvedValueOnce(result({ reply: 'Here are abayas.', cards: [card(1)] }))
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce(result({ reply: 'Cheaper picks.' }));
    const thread = setup(ask);
    await thread.send('an abaya');
    await thread.send('in red');
    expect(thread.turns().at(-1)).toMatchObject({ role: 'ain', status: 'error' });

    // Typed a new message instead of tapping Retry: "in red" is not context.
    await thread.send('cheaper');
    expect(ask).toHaveBeenLastCalledWith('cheaper', [
      { role: 'user', text: 'an abaya' },
      { role: 'ain', text: 'Here are abayas.' },
    ]);
    // The error bubble stays visible in the thread (no silent rewrite).
    expect(thread.turns().map((t) => (t.role === 'user' ? t.text : t.status))).toEqual([
      'an abaya',
      'ok',
      'in red',
      'error',
      'cheaper',
      'ok',
    ]);
  });

  it('clears the thread and its storage when the signed-in customer signs out', async () => {
    currentUser.set(user(7));
    const thread = setup(vi.fn(() => Promise.resolve(result({ reply: 'Hello!' }))));
    TestBed.tick();
    await thread.send('a gift for my sister');
    expect(thread.turns()).toHaveLength(2);
    expect(sessionStorage.getItem(AskAinThreadService.STORAGE_KEY)).not.toBeNull();

    currentUser.set(null);
    TestBed.tick();
    expect(thread.turns()).toEqual([]);
    expect(sessionStorage.getItem(AskAinThreadService.STORAGE_KEY)).toBeNull();
  });

  it('clears the thread when another account takes over, but keeps it when a guest signs in', async () => {
    const thread = setup(vi.fn(() => Promise.resolve(result({ reply: 'Hello!' }))));
    TestBed.tick();
    await thread.send('hi');

    currentUser.set(user(1)); // guest -> signed in: same person, keep
    TestBed.tick();
    expect(thread.turns()).toHaveLength(2);

    currentUser.set(user(2)); // a different account: clear
    TestBed.tick();
    expect(thread.turns()).toEqual([]);
  });
});

describe('buildHistory', () => {

  it('keeps user text + Ain reply text only, skips errors/empty replies, last 8', () => {
    const turns: ChatTurn[] = [];
    for (let i = 0; i < 6; i++) {
      turns.push({ id: `u${i}`, role: 'user', text: `q${i}` });
      turns.push({
        id: `a${i}`,
        role: 'ain',
        status: i === 2 ? 'error' : 'ok',
        reply: i === 3 ? '' : `r${i}`,
        cards: [card(i)],
        followUps: [],
        needsClarification: false,
        interactionId: i,
      });
    }
    const h = buildHistory(turns);
    expect(h).toHaveLength(8);
    expect(h[h.length - 1]).toEqual({ role: 'ain', text: 'r5' });
    expect(h.some((e) => e.text === 'r2' || e.text === '')).toBe(false);
    // The user message whose answer failed is not context either.
    expect(h.some((e) => e.text === 'q2')).toBe(false);
    for (const e of h) {
      expect(Object.keys(e).sort()).toEqual(['role', 'text']);
    }
  });
});
