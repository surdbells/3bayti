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
