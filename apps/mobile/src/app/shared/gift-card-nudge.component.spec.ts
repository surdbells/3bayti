import { TestBed } from '@angular/core/testing';

import { GiftCardNudgeComponent } from './gift-card-nudge.component';
import { I18nService } from '../i18n.service';
import { provideAxIcons } from './ax-mobile/icon';

class I18nStub {
  t = (key: string) => key;
}

function setup(inputs: Partial<Pick<GiftCardNudgeComponent, 'context' | 'variant'>> = {}) {
  TestBed.configureTestingModule({
    imports: [GiftCardNudgeComponent],
    providers: [provideAxIcons(), { provide: I18nService, useValue: new I18nStub() }],
  });
  const fixture = TestBed.createComponent(GiftCardNudgeComponent);
  Object.assign(fixture.componentInstance, inputs);
  fixture.detectChanges();
  return { fixture, component: fixture.componentInstance, el: fixture.nativeElement as HTMLElement };
}

describe('GiftCardNudgeComponent', () => {
  it('slim (PDP) renders one row: the context title + the CTA', () => {
    const { el } = setup({ context: 'pdp', variant: 'slim' });
    const row = el.querySelector('[data-testid="gift-card-nudge-slim"]') as HTMLButtonElement;

    expect(row).not.toBeNull();
    expect(row.tagName).toBe('BUTTON');
    expect(row.getAttribute('type')).toBe('button');
    expect(row.getAttribute('data-context')).toBe('pdp');
    expect(row.textContent).toContain('gift_card_nudge_pdp_title');
    expect(row.textContent).toContain('gift_card_nudge_cta');
    // The slim line carries no sub-copy (the web slim variant has none).
    expect(row.textContent).not.toContain('gift_card_nudge_pdp_sub');
    expect(el.querySelector('[data-testid="gift-card-nudge-card"]')).toBeNull();
  });

  it('card (default) renders the title, sub and CTA for its context', () => {
    const { el } = setup({ context: 'cart' });
    const card = el.querySelector('[data-testid="gift-card-nudge-card"]') as HTMLButtonElement;

    expect(card).not.toBeNull();
    expect(card.textContent).toContain('gift_card_nudge_cart_title');
    expect(card.textContent).toContain('gift_card_nudge_cart_sub');
    expect(card.textContent).toContain('gift_card_nudge_cta');
    expect(el.querySelector('[data-testid="gift-card-nudge-slim"]')).toBeNull();
  });

  it('emits (opened) on tap so the host can beacon + navigate', () => {
    const { el, component } = setup({ context: 'pdp', variant: 'slim' });
    const opened = jasmine.createSpy('opened');
    component.opened.subscribe(opened);

    (el.querySelector('[data-testid="gift-card-nudge-slim"]') as HTMLButtonElement).click();

    expect(opened).toHaveBeenCalledTimes(1);
  });
});
