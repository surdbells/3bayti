import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { ComponentFixture } from '@angular/core/testing';

import { GiftCardVisualComponent } from './gift-card-visual';
import type { GiftCard } from './gift-card.model';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { provideI18n } from '../../core/i18n';

function makeCard(o: Partial<GiftCard> = {}): GiftCard {
  return {
    id: 9, code: 'GIFT-ZZZZ-9999', theme: 'wedding',
    theme_meta: {
      label: 'Anniversary', arabic_label: 'ذكرى سنوية',
      primary_color: '#260014', accent_color: '#D4AF37', text_color: '#F5E060',
      border_color: '#D4AF37', pattern: 'rings', supports_photo: false,
    },
    denomination: '1000.00', balance: '750.00', currency: 'AED', status: 'partially_used',
    is_spendable: true, recipient_name: 'Mona', recipient_message: 'Congrats',
    recipient_photo_url: null, scheduled_delivery_at: null, activated_at: null,
    expires_at: null, created_at: '2026-06-01T00:00:00+00:00', is_buyer: true, ...o,
  };
}

describe('GiftCardVisualComponent', () => {
  let fixture: ComponentFixture<GiftCardVisualComponent>;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [GiftCardVisualComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideI18n()],
    });
    fixture = TestBed.createComponent(GiftCardVisualComponent);
  });

  afterEach(() => {
    const httpMock = TestBed.inject(HttpTestingController);
    httpMock.match(() => true).forEach((r) => { if (!r.cancelled) r.flush({}); });
    TestBed.resetTestingModule();
  });

  function set(inputs: Record<string, unknown>): void {
    for (const [k, v] of Object.entries(inputs)) fixture.componentRef.setInput(k, v);
    fixture.detectChanges();
  }
  function el(sel: string): HTMLElement | null {
    return fixture.nativeElement.querySelector(sel);
  }

  it('renders the theme motif, label and formatted amount (preview mode)', () => {
    set({ theme: 'birthday', amount: '500.00' });
    expect(el('.gc')?.getAttribute('data-pattern')).toBe('sunburst');
    expect(el('.gc__label')?.textContent?.trim()).toBe('Birthday');
    expect(el('.gc__amount')?.textContent?.trim()).toBe('AED 500');
    expect(el('.gc__code')).toBeNull();
    expect(el('.gc__status')).toBeNull();
    expect(el('.gc__photo')).toBeNull();
  });

  it('thousands are grouped in the amount', () => {
    set({ theme: 'luxury', amount: 1000 });
    expect(el('.gc__amount')?.textContent?.trim()).toBe('AED 1,000');
  });

  it('shows a framed photo only for the luxury theme', () => {
    set({ theme: 'luxury', photoUrl: 'https://cdn.example/p.jpg' });
    expect(el('.gc')?.classList.contains('gc--has-photo')).toBe(true);
    expect(el('.gc__photo img')?.getAttribute('src')).toBe('https://cdn.example/p.jpg');

    set({ theme: 'birthday' }); // supports_photo === false → ignored
    expect(el('.gc__photo')).toBeNull();
  });

  it('masks the code unless revealCode is set', () => {
    set({ theme: 'eid', code: 'GIFT-ABCD-1234' });
    expect(el('.gc__code')?.textContent?.trim()).toBe('•••• •••• 1234');

    set({ revealCode: true });
    expect(el('.gc__code')?.textContent?.trim()).toBe('GIFT-ABCD-1234');
  });

  it('shows a status badge for non-active cards, none for active', () => {
    set({ theme: 'eid', status: 'pending_payment' });
    expect(el('.gc__status')).not.toBeNull();

    set({ status: 'active' });
    expect(el('.gc__status')).toBeNull();
  });

  it('the card setter hydrates every display field at once', () => {
    set({ card: makeCard() });
    expect(el('.gc')?.getAttribute('data-pattern')).toBe('rings');
    expect(el('.gc__label')?.textContent?.trim()).toBe('Anniversary');
    expect(el('.gc__amount')?.textContent?.trim()).toBe('AED 1,000');
    expect(el('.gc__recipient')?.textContent?.trim()).toBe('To Mona');
    expect(el('.gc__balance')?.textContent?.trim()).toBe('AED 750 left');
    expect(el('.gc__status')).not.toBeNull();
  });
});
