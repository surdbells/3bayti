import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import { BehaviorSubject, of, throwError } from 'rxjs';

import { AskAinPage } from './ask-ain.page';
import { AinErrorTurn, AinReplyTurn, AskAinThreadService } from './ask-ain-thread.service';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { SESSION_PERSISTENCE, SessionStore, type SessionPersistence } from '../../core/services/session-store.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';

class MemoryPersistence implements SessionPersistence {
  constructor(public value: string | null) {}
  read(): Promise<string | null> { return Promise.resolve(this.value); }
  write(value: string): Promise<void> { this.value = value; return Promise.resolve(); }
  remove(): Promise<void> { this.value = null; return Promise.resolve(); }
}

const CARD = {
  id: 501,
  name: 'Black Abaya',
  price: { amount: 450, currency: 'AED' },
  sale_price: null,
  primary_image: { url: 'https://cdn.example/a.jpg' },
  vendor: { slug: 'atelier', name: 'Atelier', legacy_id: null, id: 9 },
  in_stock: true,
  reason: 'Elegant for a wedding',
};

function ok(data: Record<string, unknown>) {
  return of({ response_code: 200, status: 'success', message: '', data });
}

/** Routes concierge calls to `concierge`, analytics to a no-op. */
function adapterWith(concierge: jasmine.Spy) {
  return {
    post_v3: jasmine.createSpy('post_v3').and.callFake((route: string, body: unknown, opts: unknown) =>
      route === 'POST /ai/concierge/style' ? concierge(body, opts) : of({ response_code: 200, status: 'success', data: null }),
    ),
  };
}

async function setup(opts: { session?: Record<string, unknown> | null; q?: string } = {}) {
  const concierge = jasmine.createSpy('concierge');
  const adapter = adapterWith(concierge);
  const query$ = new BehaviorSubject(convertToParamMap(opts.q ? { q: opts.q } : {}));
  TestBed.configureTestingModule({
    imports: [AskAinPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: jasmine.createSpyObj('AxNotificationService', ['error', 'success']) },
      { provide: WishlistService, useValue: jasmine.createSpyObj('WishlistService', ['listLabels', 'add', 'createLabel']) },
      { provide: ActivatedRoute, useValue: { queryParamMap: query$.asObservable() } },
      {
        provide: SESSION_PERSISTENCE,
        useValue: new MemoryPersistence(opts.session ? JSON.stringify(opts.session) : null),
      },
    ],
  });
  await TestBed.inject(SessionStore).ready();
  const router = TestBed.inject(Router);
  spyOn(router, 'navigate');
  const thread = TestBed.inject(AskAinThreadService);
  return { concierge, adapter, router, thread, query$ };
}

/** Let the async session/preferences + request chain finish. */
async function settle(): Promise<void> {
  for (let i = 0; i < 20; i++) {
    await new Promise((r) => setTimeout(r, 5));
  }
}

function create() {
  const fixture = TestBed.createComponent(AskAinPage);
  fixture.detectChanges();
  return { fixture, page: fixture.componentInstance, el: fixture.nativeElement as HTMLElement };
}

describe('AskAinPage conversation', () => {
  it('shows the greeting state with suggestions for a fresh thread', async () => {
    await setup();
    const { el, page } = create();
    expect(page.isFresh).toBeTrue();
    // Grouped starter prompts: Occasions / Gifts / Styles, 4 chips each.
    expect(el.querySelectorAll('.aa-starter-group').length).toBe(3);
    expect(Array.from(el.querySelectorAll('.aa-starter-group__title')).map((h) => h.textContent?.trim())).toEqual([
      'ask_ain_group_occasions',
      'ask_ain_group_gifts',
      'ask_ain_group_styles',
    ]);
    expect(el.querySelectorAll('.aa-chip').length).toBe(12);
    // The thread's own live region is off (a whole turn with every card would
    // be read out); a separate polite line announces Ain's reply only.
    expect(el.querySelector('[role="log"][aria-live="off"]')).not.toBeNull();
    expect(el.querySelector('.aa-sr[aria-live="polite"]')?.textContent?.trim()).toBe('');
  });

  it('announces only Ain\'s reply text to screen readers', async () => {
    const { concierge } = await setup({ session: { id: 1, token: 'live', refresh_token: 'r' } });
    concierge.and.returnValue(
      ok({ interaction_id: 3, products: [CARD], reply: 'Here are elegant options.', follow_ups: [], needs_clarification: false }),
    );
    const { page } = create();
    await page.ask('black abaya');
    expect(page.announcement).toBe('Here are elegant options.');
  });

  it('sends a message and renders Ain reply, product cards and follow-up chips', async () => {
    const { concierge } = await setup({ session: { id: 1, token: 'live', refresh_token: 'r' } });
    concierge.and.returnValue(
      ok({ interaction_id: 3, products: [CARD], reply: 'Here are elegant options.', follow_ups: ['In navy', 'Cheaper ones'], needs_clarification: false }),
    );
    const { fixture, page, el } = create();

    await page.ask('black abaya for a wedding');
    fixture.detectChanges();

    const [body, opts] = concierge.calls.mostRecent().args;
    expect(body.query).toBe('black abaya for a wedding');
    expect(body.history).toBeUndefined();
    expect(body.channel).toBe('MOBILE');
    expect(opts).toEqual({ authToken: 'live' });
    expect(el.querySelector('.aa-you .aa-bubble')?.textContent).toContain('black abaya for a wedding');
    expect(el.querySelector('.aa-ain-bubble')?.textContent).toContain('Here are elegant options.');
    expect(el.querySelectorAll('.m6d-product-card').length).toBe(1);
    expect(Array.from(el.querySelectorAll('.aa-followup')).map((b) => b.textContent?.trim())).toEqual(['In navy', 'Cheaper ones']);
    expect(page.isFresh).toBeFalse();
  });

  it('a follow-up chip sends that text with the conversation as history', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(ok({ interaction_id: 3, products: [CARD], reply: 'Found these.', follow_ups: ['Cheaper ones'] }));
    const { fixture, page, el } = create();
    await page.ask('black abaya');
    fixture.detectChanges();

    concierge.and.returnValue(ok({ interaction_id: 4, products: [CARD], reply: 'Cheaper picks.', follow_ups: [] }));
    (el.querySelector('.aa-followup') as HTMLButtonElement).click();
    await fixture.whenStable();
    await settle();

    const body = concierge.calls.mostRecent().args[0];
    expect(body.query).toBe('Cheaper ones');
    expect(body.history).toEqual([
      { role: 'user', text: 'black abaya' },
      { role: 'ain', text: 'Found these.' },
    ]);
  });

  it('renders a clarifying question with answer chips and no product dump', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(
      ok({ interaction_id: 5, products: [], reply: 'Who is the gift for?', follow_ups: ['My sister', 'My mother'], needs_clarification: true }),
    );
    const { fixture, page, el } = create();
    await page.ask('a gift');
    fixture.detectChanges();

    expect(el.querySelector('.aa-ain-bubble--ask')?.textContent).toContain('Who is the gift for?');
    expect(el.querySelectorAll('.m6d-product-card').length).toBe(0);
    expect(el.querySelectorAll('.aa-followup').length).toBe(2);
  });

  it('shows an Ain error bubble with Retry, keeps the thread, and retries the same query', async () => {
    const { concierge, thread } = await setup();
    concierge.and.returnValue(throwError(() => new Error('offline')));
    const { fixture, page, el } = create();
    await page.ask('linen dress');
    fixture.detectChanges();

    expect(el.querySelector('.aa-ain-bubble--error')).not.toBeNull();
    expect(el.querySelector('.aa-you .aa-bubble')?.textContent).toContain('linen dress');

    concierge.and.returnValue(ok({ interaction_id: 6, products: [CARD], reply: 'Linen picks.', follow_ups: [] }));
    await page.retry(thread.turns()[1] as AinErrorTurn);
    fixture.detectChanges();

    expect(concierge.calls.mostRecent().args[0].query).toBe('linen dress');
    expect(el.querySelector('.aa-ain-bubble--error')).toBeNull();
    expect(el.querySelectorAll('.aa-you').length).toBe(1);
    expect(el.querySelector('.aa-ain-bubble')?.textContent).toContain('Linen picks.');
  });

  it('keeps the thread when the page is re-created (e.g. back from a product)', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(ok({ interaction_id: 3, products: [CARD], reply: 'Found these.', follow_ups: [] }));
    const first = create();
    await first.page.ask('black abaya');
    first.fixture.destroy();

    const second = create();
    expect(second.page.isFresh).toBeFalse();
    expect(second.el.querySelector('.aa-ain-bubble')?.textContent).toContain('Found these.');
  });

  it('New chat resets to the greeting state and forgets the guided questions', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(
      ok({ interaction_id: 3, products: [], reply: 'Who is it for?', follow_ups: ['For me'], needs_clarification: true, question_slot: 'recipient', asked_slots: ['recipient'] }),
    );
    const { fixture, page, el } = create();
    await page.ask('hi');
    fixture.detectChanges();
    expect(el.querySelector('.aa-new-chat')).not.toBeNull();
    page.newChat();
    fixture.detectChanges();
    expect(page.isFresh).toBeTrue();
    expect(el.querySelectorAll('.aa-chip').length).toBe(12);
    expect(el.querySelector('.aa-new-chat')).toBeNull();

    await page.ask('abaya');
    expect(concierge.calls.mostRecent().args[0].asked_slots).toEqual([]);
    expect(concierge.calls.mostRecent().args[0].history).toBeUndefined();
  });

  it('a grouped starter prompt sends its text as the first message', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(ok({ interaction_id: 3, products: [], reply: 'Lovely!', follow_ups: [] }));
    const { fixture, el } = create();
    const gifts = el.querySelectorAll('.aa-starter-group')[1];
    (gifts.querySelectorAll('.aa-chip')[1] as HTMLButtonElement).click();
    await fixture.whenStable();
    await settle();
    expect(concierge.calls.mostRecent().args[0].query).toBe('ask_ain_starter_gift_mum');
  });

  it('renders a guided question with progress, wrapping answer chips and a distinct skip chip', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(
      ok({
        interaction_id: 8,
        products: [],
        reply: 'What is the occasion?',
        follow_ups: ['Eid', 'Wedding', 'Everyday', 'No preference', 'Show me what you have'],
        needs_clarification: true,
        question_slot: 'occasion',
        asked_slots: ['recipient', 'occasion'],
      }),
    );
    const { fixture, page, el } = create();
    const i18n = TestBed.inject(I18nService);
    spyOn(i18n, 't').and.callThrough();
    await page.ask('a gift');
    fixture.detectChanges();

    expect(el.querySelector('.aa-ain-bubble--ask')?.textContent).toContain('What is the occasion?');
    expect(el.querySelector('.aa-progress')).not.toBeNull();
    expect(i18n.t).toHaveBeenCalledWith('ask_ain_question_progress', { current: 2, total: 4 });
    expect(el.querySelector('.aa-followups--answers')).not.toBeNull();
    expect(el.querySelectorAll('.aa-followup').length).toBe(5);
    const skip = el.querySelectorAll('.aa-followup--skip');
    expect(skip.length).toBe(1);
    expect(skip[0].textContent?.trim()).toBe('Show me what you have');
    expect(el.querySelectorAll('.m6d-product-card').length).toBe(0);
  });

  it('answer chips echo asked_slots; the skip chip also sends show_now', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(
      ok({ interaction_id: 8, products: [], reply: 'What is the occasion?', follow_ups: ['Eid', 'Show me what you have'], needs_clarification: true, question_slot: 'occasion', asked_slots: ['recipient', 'occasion'] }),
    );
    const { fixture, page, el } = create();
    await page.ask('a gift');
    fixture.detectChanges();

    concierge.and.returnValue(
      ok({ interaction_id: 9, products: [], reply: 'Any style or colour?', follow_ups: ['Pastels', 'Show me what you have'], needs_clarification: true, question_slot: 'style_or_colour', asked_slots: ['recipient', 'occasion', 'style_or_colour'] }),
    );
    (el.querySelector('.aa-followup:not(.aa-followup--skip)') as HTMLButtonElement).click();
    await fixture.whenStable();
    await settle();
    let body = concierge.calls.mostRecent().args[0];
    expect(body.query).toBe('Eid');
    expect(body.asked_slots).toEqual(['recipient', 'occasion']);
    expect(body.show_now).toBeUndefined();
    fixture.detectChanges();

    concierge.and.returnValue(ok({ interaction_id: 10, products: [CARD], reply: 'Here you go.', follow_ups: ['In black', 'Under 300 AED'] }));
    (el.querySelector('.aa-followup--skip') as HTMLButtonElement).click();
    await fixture.whenStable();
    await settle();
    body = concierge.calls.mostRecent().args[0];
    expect(body.query).toBe('Show me what you have');
    expect(body.show_now).toBeTrue();
    expect(body.asked_slots).toEqual(['recipient', 'occasion', 'style_or_colour']);

    fixture.detectChanges();
    // Results turn: products + refine chips, no progress hint, only the newest turn's chips.
    expect(el.querySelectorAll('.m6d-product-card').length).toBe(1);
    expect(Array.from(el.querySelectorAll('.aa-followup')).map((b) => b.textContent?.trim())).toEqual(['In black', 'Under 300 AED']);
    expect(el.querySelectorAll('.aa-progress').length).toBe(0); // only the newest question shows its hint (matches web)
    expect(el.querySelector('.aa-followups--answers')).toBeNull();
  });

  it('opening a product records the click with that turn\'s interaction id', async () => {
    const { concierge, adapter, router } = await setup();
    concierge.and.returnValue(ok({ interaction_id: 42, products: [CARD], reply: 'Found these.', follow_ups: [] }));
    const { page, thread } = { ...create(), thread: TestBed.inject(AskAinThreadService) };
    await page.ask('abaya');
    const turn = thread.turns()[1] as AinReplyTurn;
    page.open_product(CARD, turn);

    const event = adapter.post_v3.calls.all().find((c) => c.args[0] === 'POST /ai/events' && (c.args[1] as any).event === 'ai_product_clicked');
    expect((event?.args[1] as any).interaction_id).toBe(42);
    expect(router.navigate).toHaveBeenCalledWith(['/', 'product'], { queryParams: { id: 501, name: 'Black Abaya' } });
  });

  it('a ?q= deep link starts the conversation once', async () => {
    const { concierge, thread } = await setup({ q: 'eid gift' });
    concierge.and.returnValue(ok({ interaction_id: 3, products: [CARD], reply: 'Gift ideas.', follow_ups: [] }));
    const first = create();
    await settle();
    await first.fixture.whenStable();
    expect(concierge).toHaveBeenCalledTimes(1);
    expect(concierge.calls.mostRecent().args[0].query).toBe('eid gift');
    first.fixture.destroy();

    create();
    await settle();
    expect(concierge).toHaveBeenCalledTimes(1);
    expect(thread.turns().length).toBe(2);
  });

  it('offers Retry only on the newest error turn', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(throwError(() => new Error('offline')));
    const { fixture, page, el } = create();
    await page.ask('linen dress');
    fixture.detectChanges();
    expect(el.querySelectorAll('.aa-retry').length).toBe(1);

    concierge.and.returnValue(ok({ interaction_id: 7, products: [], reply: 'Shoes!', follow_ups: [] }));
    await page.ask('shoes');
    fixture.detectChanges();
    // The old error bubble stays, but without a Retry that would misorder the thread.
    expect(el.querySelectorAll('.aa-ain-bubble--error').length).toBe(1);
    expect(el.querySelectorAll('.aa-retry').length).toBe(0);
  });

  it('keeps focus in the composer after tapping a follow-up chip', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(ok({ interaction_id: 3, products: [], reply: 'Found these.', follow_ups: ['Cheaper ones'] }));
    const { fixture, page, el } = create();
    await page.ask('black abaya');
    fixture.detectChanges();

    const chip = el.querySelector('.aa-followup') as HTMLButtonElement;
    const down = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
    chip.dispatchEvent(down);
    expect(down.defaultPrevented).toBeTrue();
    chip.click();
    await settle();
    expect(document.activeElement).toBe(el.querySelector('textarea.aa-input'));
  });

  it('a fresh navigation with the same ?q= starts the conversation again', async () => {
    const { concierge, thread } = await setup({ q: 'eid gift' });
    concierge.and.returnValue(ok({ interaction_id: 3, products: [CARD], reply: 'Gift ideas.', follow_ups: [] }));
    const nav = spyOn(AskAinPage.prototype as any, 'navigationKey').and.returnValue('1');
    const first = create();
    await settle();
    first.fixture.destroy();
    thread.reset();

    nav.and.returnValue('2');
    create();
    await settle();
    expect(concierge).toHaveBeenCalledTimes(2);
    expect(thread.turns().length).toBe(2);
  });

  it('a different account signing in clears the previous customer thread on open', async () => {
    const { concierge, thread } = await setup({ session: { id: 1, token: 'a', refresh_token: 'r' } });
    concierge.and.returnValue(ok({ interaction_id: 3, products: [], reply: 'For your sister.', follow_ups: [] }));
    const first = create();
    await first.page.ask('gift for my sister');
    first.fixture.destroy();
    expect(thread.turns().length).toBe(2);

    await TestBed.inject(SessionStore).signIn({ id: 2, token: 'b', refresh_token: 'r2' });
    const second = create();
    expect(second.page.isFresh).toBeTrue();
  });

  it('uses translated accessible names (no hard-coded English)', async () => {
    const { concierge } = await setup();
    concierge.and.returnValue(ok({ interaction_id: 3, products: [CARD], reply: 'Found these.', follow_ups: [] }));
    const { fixture, page, el } = create();
    await page.ask('abaya');
    fixture.detectChanges();
    expect(el.querySelector('.m7-header-back')?.getAttribute('aria-label')).not.toBe('Back');
    expect(el.querySelector('.m6d-like')?.getAttribute('aria-label')).not.toBe('Add to closet');
    expect(el.querySelector('.m6d-like')?.getAttribute('aria-label')).toBeTruthy();
  });
});
