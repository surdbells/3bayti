import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import {
  AxSkeletonBlockComponent,
  AxSkeletonCardComponent,
  AxSkeletonCircleComponent,
  AxSkeletonLineComponent,
} from './index';

@Component({
  standalone: true,
  imports: [
    AxSkeletonLineComponent,
    AxSkeletonBlockComponent,
    AxSkeletonCircleComponent,
    AxSkeletonCardComponent,
  ],
  template: `
    <ax-skeleton-line id="line" width="60%" [height]="14" />
    <ax-skeleton-line id="line-media" tone="on-media" />
    <ax-skeleton-block id="chip" width="88px" height="34px" radius="999px" />
    <ax-skeleton-block id="frame" aspectRatio="3/4" />
    <div style="position: relative; width: 50px; height: 50px">
      <ax-skeleton-block id="fill" [fill]="true" radius="50%" tone="on-media" />
    </div>
    <ax-skeleton-circle id="circle" [size]="26" />
    <ax-skeleton-card id="card" />
    <ax-skeleton-card id="card-bare" [showAction]="false" />
  `,
})
class HostComponent {}

describe('ax-skeleton primitives', () => {
  let root: HTMLElement;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent] });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    root = fixture.nativeElement as HTMLElement;
  });

  const host = (id: string) => root.querySelector(`#${id}`) as HTMLElement;
  const paint = (id: string) => host(id).querySelector('.ax-skeleton') as HTMLElement;

  it('puts line geometry on the host (so % widths resolve against the real parent) and hides it from AT', () => {
    expect(host('line').style.width).toBe('60%');
    expect(host('line').style.height).toBe('14px');
    expect(host('line').getAttribute('aria-hidden')).toBe('true');
    expect(paint('line').classList).toContain('ax-skeleton--line');
    expect(paint('line').classList).not.toContain('ax-skeleton--on-media');
    expect(paint('line-media').classList).toContain('ax-skeleton--on-media');
  });

  it('sizes a block from width/height or aspect-ratio and applies the radius to the paint', () => {
    expect(host('chip').style.width).toBe('88px');
    expect(host('chip').style.height).toBe('34px');
    expect(paint('chip').style.borderRadius).toBe('999px');
    expect(host('frame').style.aspectRatio.replace(/\s/g, '')).toBe('3/4');
    expect(host('frame').getBoundingClientRect().height).toBeGreaterThan(0);
  });

  it('fill mode covers the positioned ancestor instead of taking its own size', () => {
    const fill = host('fill');
    expect(fill.classList).toContain('ax-skeleton-host--fill');
    expect(fill.style.width).toBe('');
    expect(getComputedStyle(fill).position).toBe('absolute');
    const rect = fill.getBoundingClientRect();
    expect(Math.round(rect.width)).toBe(50);
    expect(Math.round(rect.height)).toBe(50);
    expect(paint('fill').style.borderRadius).toBe('50%');
    expect(paint('fill').classList).toContain('ax-skeleton--on-media');
  });

  it('renders a circle of the requested diameter', () => {
    const rect = host('circle').getBoundingClientRect();
    expect(Math.round(rect.width)).toBe(26);
    expect(Math.round(rect.height)).toBe(26);
    expect(paint('circle').classList).toContain('ax-skeleton--circle');
  });

  it('the card reuses the canonical .m6d product-card frame (same geometry as the real card)', () => {
    const card = host('card');
    expect(card.getAttribute('aria-hidden')).toBe('true');
    expect(card.querySelector('.m6d-product-card .m6d-img-wrap .m6d-img-skeleton .skeleton-shimmer')).not.toBeNull();
    expect(card.querySelector('.m6d-info .m6d-name ax-skeleton-line')).not.toBeNull();
    expect(card.querySelector('.m6d-price-row ax-skeleton-circle')).not.toBeNull();
    expect(host('card-bare').querySelector('.m6d-price-row ax-skeleton-circle')).toBeNull();
  });
});
