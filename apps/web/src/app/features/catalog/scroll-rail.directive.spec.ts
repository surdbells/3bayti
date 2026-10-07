import { describe, it, expect, beforeEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Component, ViewChild } from '@angular/core';

import { ScrollRailDirective } from './scroll-rail.directive';

@Component({
  standalone: true,
  imports: [ScrollRailDirective],
  template: `
    <ul appScrollRail #rail="scrollRail" [style.direction]="dir">
      <li data-rail-item><a href="/product/a">A</a></li>
      <li data-rail-item><a href="/product/b">B</a></li>
      <li data-rail-item><a href="/product/c">C</a></li>
    </ul>
    <button type="button" class="prev" (click)="rail.scroll(-1)" [attr.aria-disabled]="rail.atStart() ? 'true' : null">Prev</button>
    <button type="button" class="next" (click)="rail.scroll(1)" [attr.aria-disabled]="rail.atEnd() ? 'true' : null">Next</button>
  `,
})
class HostComponent {
  dir: 'ltr' | 'rtl' = 'ltr';
  @ViewChild('rail') rail!: ScrollRailDirective;
}

function setup(dir: 'ltr' | 'rtl' = 'ltr'): { fixture: ComponentFixture<HostComponent>; ul: HTMLUListElement; links: HTMLAnchorElement[] } {
  TestBed.configureTestingModule({ imports: [HostComponent] });
  const fixture = TestBed.createComponent(HostComponent);
  fixture.componentInstance.dir = dir;
  fixture.detectChanges();
  const ul = (fixture.nativeElement as HTMLElement).querySelector('ul')!;
  return { fixture, ul, links: Array.from(ul.querySelectorAll('a')) };
}

function key(target: Element, k: string): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true });
  target.dispatchEvent(event);
  return event;
}

/** Fake the scroll geometry jsdom doesn't lay out. */
function geometry(el: HTMLElement, g: { scrollWidth: number; clientWidth: number; scrollLeft: number }): void {
  Object.defineProperty(el, 'scrollWidth', { configurable: true, get: () => g.scrollWidth });
  Object.defineProperty(el, 'clientWidth', { configurable: true, get: () => g.clientWidth });
  Object.defineProperty(el, 'scrollLeft', { configurable: true, get: () => g.scrollLeft, set: () => undefined });
}

describe('ScrollRailDirective', () => {
  beforeEach(() => TestBed.resetTestingModule());

  it('moves focus item-to-item with the arrow keys, Home and End', () => {
    const { links } = setup();
    links[0].focus();
    expect(key(links[0], 'ArrowRight').defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(links[1]);
    key(links[1], 'End');
    expect(document.activeElement).toBe(links[2]);
    key(links[2], 'ArrowRight'); // already last: stays
    expect(document.activeElement).toBe(links[2]);
    key(links[2], 'ArrowLeft');
    expect(document.activeElement).toBe(links[1]);
    key(links[1], 'Home');
    expect(document.activeElement).toBe(links[0]);
  });

  it('follows the visual order in RTL (ArrowLeft is "next")', () => {
    const { links } = setup('rtl');
    links[0].focus();
    key(links[0], 'ArrowLeft');
    expect(document.activeElement).toBe(links[1]);
    key(links[1], 'ArrowRight');
    expect(document.activeElement).toBe(links[0]);
  });

  it('ignores other keys', () => {
    const { links } = setup();
    links[0].focus();
    expect(key(links[0], 'Enter').defaultPrevented).toBe(false);
    expect(document.activeElement).toBe(links[0]);
  });

  it('reports the edge state from the scroll position (absolute, so RTL negatives work)', () => {
    const { fixture, ul } = setup();
    const rail = fixture.componentInstance.rail;

    geometry(ul, { scrollWidth: 300, clientWidth: 300, scrollLeft: 0 });
    rail.update();
    expect(rail.overflowing()).toBe(false);

    geometry(ul, { scrollWidth: 900, clientWidth: 300, scrollLeft: 0 });
    rail.update();
    expect([rail.overflowing(), rail.atStart(), rail.atEnd()]).toEqual([true, true, false]);

    geometry(ul, { scrollWidth: 900, clientWidth: 300, scrollLeft: -600 }); // RTL, scrolled to the end
    rail.update();
    expect([rail.atStart(), rail.atEnd()]).toEqual([false, true]);
  });

  it('keeps an edge arrow focusable (aria-disabled) with a no-op click, so focus never drops at either end', () => {
    const { fixture, ul } = setup();
    const root = fixture.nativeElement as HTMLElement;
    const prev = root.querySelector<HTMLButtonElement>('button.prev')!;
    const next = root.querySelector<HTMLButtonElement>('button.next')!;
    // 900px of items in a 300px window; scrollBy moves (clamped) and fires scroll.
    let scrollLeft = 0;
    Object.defineProperty(ul, 'scrollWidth', { configurable: true, get: () => 900 });
    Object.defineProperty(ul, 'clientWidth', { configurable: true, get: () => 300 });
    Object.defineProperty(ul, 'scrollLeft', {
      configurable: true,
      get: () => scrollLeft,
      set: (v: number) => { scrollLeft = v; },
    });
    const scrollBy = vi.fn((opts: ScrollToOptions) => {
      scrollLeft = Math.min(600, Math.max(0, scrollLeft + (opts.left ?? 0)));
      ul.dispatchEvent(new Event('scroll'));
    });
    (ul as unknown as { scrollBy: typeof scrollBy }).scrollBy = scrollBy;
    ul.dispatchEvent(new Event('scroll'));
    fixture.detectChanges();
    expect(prev.getAttribute('aria-disabled')).toBe('true');
    expect(next.getAttribute('aria-disabled')).toBeNull();

    // "Next" until the end (240 + 240 + the last 120px): focus stays on it.
    next.focus();
    for (let i = 0; i < 3; i++) {
      next.click();
      fixture.detectChanges();
    }
    expect(scrollLeft).toBe(600);
    expect(next.getAttribute('aria-disabled')).toBe('true');
    expect(next.disabled).toBe(false);
    expect(document.activeElement).toBe(next);
    next.click();
    fixture.detectChanges();
    expect(scrollBy).toHaveBeenCalledTimes(3); // no-op at the end
    expect(document.activeElement).toBe(next);
    expect(prev.getAttribute('aria-disabled')).toBeNull();

    // And "Previous" back to the start.
    prev.focus();
    for (let i = 0; i < 3; i++) {
      prev.click();
      fixture.detectChanges();
    }
    expect(scrollLeft).toBe(0);
    expect(prev.getAttribute('aria-disabled')).toBe('true');
    expect(document.activeElement).toBe(prev);
    prev.click();
    fixture.detectChanges();
    expect(scrollBy).toHaveBeenCalledTimes(6); // no-op at the start
    expect(document.activeElement).toBe(prev);
    expect(next.getAttribute('aria-disabled')).toBeNull();
  });

  it('scrolls about one page towards the inline end, mirrored in RTL', () => {
    const ltr = setup();
    geometry(ltr.ul, { scrollWidth: 900, clientWidth: 300, scrollLeft: 0 });
    const scrollBy = vi.fn();
    (ltr.ul as unknown as { scrollBy: typeof scrollBy }).scrollBy = scrollBy;
    ltr.fixture.componentInstance.rail.scroll(1);
    expect(scrollBy).toHaveBeenCalledWith(expect.objectContaining({ left: 240 }));

    TestBed.resetTestingModule();
    const rtl = setup('rtl');
    geometry(rtl.ul, { scrollWidth: 900, clientWidth: 300, scrollLeft: 0 });
    const scrollByRtl = vi.fn();
    (rtl.ul as unknown as { scrollBy: typeof scrollByRtl }).scrollBy = scrollByRtl;
    rtl.fixture.componentInstance.rail.scroll(1);
    expect(scrollByRtl).toHaveBeenCalledWith(expect.objectContaining({ left: -240 }));
  });
});
