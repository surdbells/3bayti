import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { PLATFORM_ID } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';

import { ShareButtonsComponent } from './share-buttons';
import { provideI18n } from '../../core/i18n';

/**
 * Coverage for the PDP social-share row (Phase C4, decision #5): six
 * channels, WhatsApp, copy link, native share, Facebook, X, Telegram.
 * Exercises the URL builders, the safe new-tab anchors, the copy flow
 * (clipboard + transient confirmation), and the Web Share API
 * feature-detection (hidden when unsupported, invoked when present).
 */
const SHARE_URL = 'https://staging.3bayti.ae/product/silk-abaya';
const SHARE_TITLE = 'Silk Abaya';

function setup(): { fixture: ComponentFixture<ShareButtonsComponent>; component: ShareButtonsComponent } {
  TestBed.configureTestingModule({
    imports: [ShareButtonsComponent],
    providers: [
      { provide: PLATFORM_ID, useValue: 'browser' },
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
    ],
  });
  const fixture = TestBed.createComponent(ShareButtonsComponent);
  fixture.componentRef.setInput('url', SHARE_URL);
  fixture.componentRef.setInput('title', SHARE_TITLE);
  fixture.detectChanges();
  return { fixture, component: fixture.componentInstance };
}

describe('ShareButtonsComponent (C4, #5)', () => {
  afterEach(() => {
    const httpMock = TestBed.inject(HttpTestingController);
    httpMock.match(() => true).forEach((r) => { if (!r.cancelled) r.flush({}); });
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
    delete (navigator as unknown as { share?: unknown }).share;
    delete (navigator as unknown as { clipboard?: unknown }).clipboard;
  });

  it('builds correct network share URLs with encoded url + title', () => {
    const { component } = setup();
    expect(component.whatsappUrl()).toBe(
      `https://wa.me/?text=${encodeURIComponent(`${SHARE_TITLE} ${SHARE_URL}`)}`,
    );
    expect(component.facebookUrl()).toBe(
      `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(SHARE_URL)}`,
    );
    expect(component.xUrl()).toContain(`url=${encodeURIComponent(SHARE_URL)}`);
    expect(component.xUrl()).toContain(`text=${encodeURIComponent(SHARE_TITLE)}`);
    expect(component.telegramUrl()).toContain(
      `https://t.me/share/url?url=${encodeURIComponent(SHARE_URL)}`,
    );
  });

  it('renders four network anchors that open in a new tab safely', () => {
    const { fixture } = setup();
    const anchors = fixture.nativeElement.querySelectorAll('a.share__btn') as NodeListOf<HTMLAnchorElement>;
    expect(anchors.length).toBe(4);
    anchors.forEach((a) => {
      expect(a.getAttribute('target')).toBe('_blank');
      expect(a.getAttribute('rel')).toContain('noopener');
    });
  });

  it('copies the link and shows a transient confirmation', async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    const { fixture, component } = setup();
    await component.copyLink();
    fixture.detectChanges();
    expect(writeText).toHaveBeenCalledWith(SHARE_URL);
    expect(component.copied()).toBe(true);
    expect(fixture.nativeElement.querySelector('.share__status')).not.toBeNull();
  });

  it('hides the native-share button when the Web Share API is unavailable', () => {
    delete (navigator as unknown as { share?: unknown }).share;
    const { fixture, component } = setup();
    expect(component.canNativeShare()).toBe(false);
    /* 4 network anchors + 1 copy button, no native button. */
    expect(fixture.nativeElement.querySelectorAll('.share__btn').length).toBe(5);
  });

  it('invokes the Web Share API when available', async () => {
    const share = vi.fn(() => Promise.resolve());
    Object.defineProperty(navigator, 'share', { value: share, configurable: true });
    const { fixture, component } = setup();
    expect(component.canNativeShare()).toBe(true);
    /* native button now rendered: 4 anchors + copy + native = 6. */
    expect(fixture.nativeElement.querySelectorAll('.share__btn').length).toBe(6);
    await component.nativeShare();
    expect(share).toHaveBeenCalledWith({ title: SHARE_TITLE, url: SHARE_URL });
  });
});
