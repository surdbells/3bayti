import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { Subject, of, throwError } from 'rxjs';

import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { SESSION_PERSISTENCE, SessionStore, type SessionPersistence } from '../../core/services/session-store.service';
import { AIN_HISTORY_LIMIT, AinReplyTurn, AskAinThreadService } from './ask-ain-thread.service';

const CTX = { locale: 'en', sessionId: 's-1' };

function ok(data: Record<string, unknown>) {
  return { response_code: 200, status: 'success', message: '', data };
}

function reply(text: string, extra: Record<string, unknown> = {}) {
  return ok({ interaction_id: 7, intent: {}, products: [], reply: text, follow_ups: [], needs_clarification: false, ...extra });
}

class MemoryPersistence implements SessionPersistence {
  constructor(public value: string | null) {}
  read(): Promise<string | null> { return Promise.resolve(this.value); }
  write(value: string): Promise<void> { this.value = value; return Promise.resolve(); }
  remove(): Promise<void> { this.value = null; return Promise.resolve(); }
}

function setup(session: Record<string, unknown> | null = null) {
  const adapter = { post_v3: jasmine.createSpy('post_v3') };
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: SESSION_PERSISTENCE, useValue: new MemoryPersistence(session ? JSON.stringify(session) : null) },
    ],
  });
  return { thread: TestBed.inject(AskAinThreadService), adapter, session: TestBed.inject(SessionStore) };
}

describe('AskAinThreadService', () => {
  it('sends the first message without history and appends user + Ain turns', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of(reply('Here are some abayas.', { follow_ups: ['In black', 'Cheaper'] })));

    await thread.send('  black abaya  ', { ...CTX, authToken: 'tok' });

    const [route, body, opts] = adapter.post_v3.calls.mostRecent().args;
    expect(route).toBe('POST /ai/concierge/style');
    expect(body).toEqual({ query: 'black abaya', locale: 'en', session_id: 's-1', channel: 'MOBILE', asked_slots: [] });
    expect(opts).toEqual({ authToken: 'tok' });
    const turns = thread.turns();
    expect(turns.map((t) => t.role)).toEqual(['user', 'ain']);
    const ain = turns[1] as AinReplyTurn;
    expect(ain.text).toBe('Here are some abayas.');
    expect(ain.followUps).toEqual(['In black', 'Cheaper']);
    expect(ain.interactionId).toBe(7);
    expect(thread.pending()).toBeFalse();
  });

  it('sends the previous conversation (text only) as history on the next message', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of(reply('Found these.', { products: [{ id: 1, name: 'Abaya' }] })));
    await thread.send('black abaya', CTX);
    await thread.send('cheaper', CTX);

    const body = adapter.post_v3.calls.mostRecent().args[1];
    expect(body.query).toBe('cheaper');
    expect(body.history).toEqual([
      { role: 'user', text: 'black abaya' },
      { role: 'ain', text: 'Found these.' },
    ]);
    // Never product payloads.
    expect(body.history.every((h: object) => Object.keys(h).sort().join() === 'role,text')).toBeTrue();
  });

  it('leaves an unanswered (failed) customer message out of the history', () => {
    const { thread } = setup();
    expect(
      thread.history([
        { id: 1, role: 'user', text: 'black abaya' },
        { id: 2, role: 'ain', text: 'Found these.', products: [], followUps: [], needsClarification: false, interactionId: null },
        { id: 3, role: 'user', text: 'in red' },
        { id: 4, role: 'error', query: 'in red' },
      ]),
    ).toEqual([
      { role: 'user', text: 'black abaya' },
      { role: 'ain', text: 'Found these.' },
    ]);
  });

  it('keeps only the last 8 history entries, each capped at 500 chars', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of(reply('ok')));
    for (let i = 0; i < 6; i++) {
      await thread.send(i === 5 ? 'x'.repeat(600) : 'msg ' + i, CTX);
    }
    const history = thread.history();
    expect(history.length).toBe(AIN_HISTORY_LIMIT);
    expect(history[0]).toEqual({ role: 'user', text: 'msg 2' });
    expect(history.every((h) => h.text.length <= 500)).toBeTrue();
  });

  it('caps and cleans follow-ups and reply text (no URLs / markup)', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(
      of(
        reply('<b>Hi</b> see https://evil.example now', {
          follow_ups: ['a', '', 42, 'y'.repeat(60), 'c', 'd'],
        }),
      ),
    );
    await thread.send('hi', CTX);
    const ain = thread.turns()[1] as AinReplyTurn;
    expect(ain.text).toBe('Hi see now');
    expect(ain.followUps).toEqual(['a', 'y'.repeat(40), 'c', 'd']);
    expect(ain.followUps[1].length).toBe(40);
  });

  it('keeps up to 6 refine chips on a results turn', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(
      of(reply('Found these.', { products: [{ id: 1, name: 'Abaya' }], follow_ups: ['1', '2', '3', '4', '5', '6', '7', '8'] })),
    );
    await thread.send('abaya', CTX);
    const ain = thread.turns()[1] as AinReplyTurn;
    expect(ain.followUps).toEqual(['1', '2', '3', '4', '5', '6']);
    expect(ain.questionSlot).toBeNull();
    expect(ain.needsClarification).toBeFalse();
  });

  describe('guided discovery', () => {
    const ANSWERS = ['Eid', 'Wedding', 'Everyday', 'Work', 'Ramadan', 'Party/evening', 'No preference', 'Show me what you have', 'Extra'];

    function question(slot: string, asked: unknown, extra: Record<string, unknown> = {}) {
      return reply('What is the occasion?', {
        question_slot: slot,
        asked_slots: asked,
        needs_clarification: true,
        follow_ups: ANSWERS,
        ...extra,
      });
    }

    it('reads a question turn: slot, running asked_slots and up to 8 answer chips incl. the skip chip', async () => {
      const { thread, adapter } = setup();
      adapter.post_v3.and.returnValue(of(question('occasion', ['recipient', 'occasion', 'bogus', 'occasion'])));
      await thread.send('a gift', CTX);
      const ain = thread.turns()[1] as AinReplyTurn;
      expect(ain.questionSlot).toBe('occasion');
      expect(ain.askedSlots).toEqual(['recipient', 'occasion']);
      expect(ain.needsClarification).toBeTrue();
      expect(ain.products).toEqual([]);
      expect(ain.followUps.length).toBe(8);
      expect(ain.followUps).toContain('No preference');
      expect(ain.followUps).toContain('Show me what you have');
    });

    it('echoes the previous response asked_slots on the next message (unknown values dropped)', async () => {
      const { thread, adapter } = setup();
      adapter.post_v3.and.returnValue(of(question('recipient', ['recipient'])));
      await thread.send('something nice', CTX);
      // First request: nothing asked yet, but always sent (absent = legacy client).
      expect(adapter.post_v3.calls.mostRecent().args[1].asked_slots).toEqual([]);

      adapter.post_v3.and.returnValue(of(question('occasion', ['recipient', 'occasion', 'nope'])));
      await thread.send('A gift', CTX);
      expect(adapter.post_v3.calls.mostRecent().args[1].asked_slots).toEqual(['recipient']);

      adapter.post_v3.and.returnValue(of(reply('Here you go.', { products: [{ id: 1, name: 'A' }], asked_slots: ['recipient', 'occasion'] })));
      await thread.send('Eid', CTX);
      const body = adapter.post_v3.calls.mostRecent().args[1];
      expect(body.asked_slots).toEqual(['recipient', 'occasion']);
      expect(body.show_now).toBeUndefined();
    });

    it('sends show_now only when asked (the skip chip)', async () => {
      const { thread, adapter } = setup();
      adapter.post_v3.and.returnValue(of(question('recipient', ['recipient'])));
      await thread.send('abaya', CTX);
      adapter.post_v3.and.returnValue(of(reply('Here you go.', { products: [{ id: 1, name: 'A' }], asked_slots: ['recipient'] })));
      await thread.send('Show me what you have', CTX, { showNow: true });
      const body = adapter.post_v3.calls.mostRecent().args[1];
      expect(body.show_now).toBeTrue();
      expect(body.asked_slots).toEqual(['recipient']);
    });

    it('adds the skip chip to a question turn when the server left it out', async () => {
      const { thread, adapter } = setup();
      adapter.post_v3.and.returnValue(of(question('budget', ['budget'], { follow_ups: ['Under 300 AED', 'No preference'] })));
      await thread.send('abaya', CTX);
      const ain = thread.turns()[1] as AinReplyTurn;
      expect(ain.followUps).toEqual(['Under 300 AED', 'No preference', 'ask_ain_show_now']);
      expect(thread.isSkipChip(ain.followUps[2])).toBeTrue();
    });

    it('works with an older API (no question_slot / asked_slots): no slots echoed, chips as sent', async () => {
      const { thread, adapter } = setup();
      adapter.post_v3.and.returnValue(of(reply('Who is it for?', { needs_clarification: true, follow_ups: ['My sister'] })));
      await thread.send('a gift', CTX);
      const ain = thread.turns()[1] as AinReplyTurn;
      expect(ain.questionSlot).toBeNull();
      expect(ain.askedSlots).toEqual([]);
      expect(ain.followUps).toEqual(['My sister']);
      await thread.send('My sister', CTX);
      expect(adapter.post_v3.calls.mostRecent().args[1].asked_slots).toEqual([]);
    });

    it('recognises the skip chip in English and Arabic', () => {
      const { thread } = setup();
      expect(thread.isSkipChip('Show me what you have')).toBeTrue();
      expect(thread.isSkipChip('show me what you have!')).toBeTrue();
      expect(thread.isSkipChip('أريني ما لديكِ')).toBeTrue();
      expect(thread.isSkipChip('أرني ما لديك')).toBeTrue();
      expect(thread.isSkipChip('Show me cheaper ones')).toBeFalse();
      expect(thread.isSkipChip('No preference')).toBeFalse();
    });

    it('reset (New chat) forgets the asked slots', async () => {
      const { thread, adapter } = setup();
      adapter.post_v3.and.returnValue(of(question('occasion', ['recipient', 'occasion'])));
      await thread.send('a gift', CTX);
      expect(thread.askedSlots()).toEqual(['recipient', 'occasion']);
      thread.reset();
      expect(thread.askedSlots()).toEqual([]);
      await thread.send('abaya', CTX);
      expect(adapter.post_v3.calls.mostRecent().args[1].asked_slots).toEqual([]);
    });

    it('a retry resends show_now and the asked slots of the failed turn', async () => {
      const { thread, adapter } = setup();
      adapter.post_v3.and.returnValue(of(question('occasion', ['recipient', 'occasion'])));
      await thread.send('a gift', CTX);
      adapter.post_v3.and.returnValue(throwError(() => new Error('offline')));
      await thread.send('Show me what you have', CTX, { showNow: true });
      const errorId = thread.turns()[3].id;

      adapter.post_v3.and.returnValue(of(reply('Here you go.', { products: [{ id: 1, name: 'A' }] })));
      await thread.retry(errorId, CTX);
      const body = adapter.post_v3.calls.mostRecent().args[1];
      expect(body.query).toBe('Show me what you have');
      expect(body.show_now).toBeTrue();
      expect(body.asked_slots).toEqual(['recipient', 'occasion']);
    });
  });

  it('falls back to a localized line when the API sends no reply', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of(ok({ interaction_id: 1, products: [] })));
    await thread.send('anything', CTX);
    const ain = thread.turns()[1] as AinReplyTurn;
    expect(ain.text).toBe('ask_ain_no_results');
    expect(ain.followUps).toEqual([]);
    expect(ain.needsClarification).toBeFalse();
  });

  it('adds an error turn on failure and retries without duplicating the user bubble', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of(reply('first')));
    await thread.send('dress', CTX);
    adapter.post_v3.and.returnValue(throwError(() => new Error('offline')));
    await thread.send('in red', CTX);
    expect(thread.turns().map((t) => t.role)).toEqual(['user', 'ain', 'user', 'error']);

    adapter.post_v3.and.returnValue(of(reply('Red ones.')));
    const errorId = thread.turns()[3].id;
    await thread.retry(errorId, CTX);

    expect(thread.turns().map((t) => t.role)).toEqual(['user', 'ain', 'user', 'ain']);
    const body = adapter.post_v3.calls.mostRecent().args[1];
    expect(body.query).toBe('in red');
    expect(body.history).toEqual([
      { role: 'user', text: 'dress' },
      { role: 'ain', text: 'first' },
    ]);
  });

  it('treats a non-200 envelope as an error turn', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of({ response_code: 503, status: 'error', message: 'down', data: null }));
    await thread.send('dress', CTX);
    expect(thread.turns().map((t) => t.role)).toEqual(['user', 'error']);
  });

  it('ignores a send while a reply is pending', async () => {
    const { thread, adapter } = setup();
    const res = new Subject<unknown>();
    adapter.post_v3.and.returnValue(res);
    const first = thread.send('one', CTX);
    expect(thread.pending()).toBeTrue();
    expect(await thread.send('two', CTX)).toBeFalse();
    res.next(reply('done'));
    res.complete();
    await first;
    expect(thread.turns().map((t) => t.role)).toEqual(['user', 'ain']);
  });

  it('reset clears the thread and drops an answer from the previous thread', async () => {
    const { thread, adapter } = setup();
    const res = new Subject<unknown>();
    adapter.post_v3.and.returnValue(res);
    const first = thread.send('one', CTX);
    thread.reset();
    expect(thread.turns()).toEqual([]);
    expect(thread.pending()).toBeFalse();
    res.next(reply('late'));
    res.complete();
    await first;
    expect(thread.turns()).toEqual([]);
    expect(thread.history()).toEqual([]);
  });

  it('is a root singleton, so the thread survives the page being re-created', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of(reply('kept')));
    await thread.send('keep me', CTX);
    expect(TestBed.inject(AskAinThreadService)).toBe(thread);
    expect(TestBed.inject(AskAinThreadService).turns().length).toBe(2);
  });

  it('refuses to retry an error that is no longer the newest turn (keeps the order)', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(throwError(() => new Error('offline')));
    await thread.send('dress', CTX);
    const staleErrorId = thread.turns()[1].id;
    adapter.post_v3.and.returnValue(of(reply('Shoes!')));
    await thread.send('shoes', CTX);
    expect(thread.turns().map((t) => t.role)).toEqual(['user', 'error', 'user', 'ain']);
    expect(thread.isLast(staleErrorId)).toBeFalse();

    const calls = adapter.post_v3.calls.count();
    expect(await thread.retry(staleErrorId, CTX)).toBeFalse();
    expect(adapter.post_v3.calls.count()).toBe(calls);
    expect(thread.turns().map((t) => t.role)).toEqual(['user', 'error', 'user', 'ain']);
  });

  it('shows the user bubble and pending state synchronously while the context resolves', async () => {
    const { thread, adapter } = setup();
    adapter.post_v3.and.returnValue(of(reply('ok')));
    let resolveCtx!: (c: typeof CTX) => void;
    const ctx = new Promise<typeof CTX>((r) => (resolveCtx = r));

    const first = thread.send('one', ctx);
    expect(thread.turns().map((t) => t.role)).toEqual(['user']);
    expect(thread.pending()).toBeTrue();
    // A second tap during the context wait is refused, not raced.
    expect(await thread.send('two', CTX)).toBeFalse();

    resolveCtx(CTX);
    expect(await first).toBeTrue();
    expect(adapter.post_v3).toHaveBeenCalledTimes(1);
    expect(thread.turns().map((t) => t.role)).toEqual(['user', 'ain']);
  });

  it('a reset while the context resolves sends nothing', async () => {
    const { thread, adapter } = setup();
    let resolveCtx!: (c: typeof CTX) => void;
    const first = thread.send('one', new Promise<typeof CTX>((r) => (resolveCtx = r)));
    thread.reset();
    resolveCtx(CTX);
    await first;
    expect(adapter.post_v3).not.toHaveBeenCalled();
    expect(thread.turns()).toEqual([]);
  });

  it('clears the thread when the session ends (logout), so the next customer starts fresh', async () => {
    const { thread, adapter, session } = setup({ id: 11, token: 'a', refresh_token: 'r' });
    await session.ready();
    adapter.post_v3.and.returnValue(of(reply('Gift ideas for your sister.')));
    await thread.send('gift for my sister under 300', CTX);
    expect(thread.turns().length).toBe(2);

    await session.endSession('logout');
    expect(thread.turns()).toEqual([]);
    expect(thread.history()).toEqual([]);
  });

  it('clears the thread when a different account is signed in, but keeps it on sign-in from signed-out', async () => {
    const { thread, adapter, session } = setup();
    await session.ready();
    adapter.post_v3.and.returnValue(of(reply('ok')));
    await thread.send('signed-out message', CTX);

    await session.signIn({ id: 11, token: 'a', refresh_token: 'r' });
    thread.ensureOwner();
    expect(thread.turns().length).toBe(2);
    await thread.send('as A', CTX);
    expect(thread.turns().length).toBe(4);

    await session.signIn({ id: 22, token: 'b', refresh_token: 'r2' });
    await thread.send('as B', CTX);
    // A's turns are gone and were not sent as B's history.
    expect(thread.turns().map((t) => (t as { text?: string }).text)).toEqual(['as B', 'ok']);
    expect(adapter.post_v3.calls.mostRecent().args[1].history).toBeUndefined();
  });
});
