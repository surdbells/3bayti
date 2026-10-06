import { describe, it, expect, afterEach } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslateService, provideTranslateService } from '@ngx-translate/core';

import { CollectionCardComponent } from './collection-card';
import type { Collection } from './collection.model';

function makeCollection(o: Partial<Collection> = {}): Collection {
  return {
    id: 7,
    slug: 'eid-edit',
    name: 'Eid Edit',
    description: null,
    image_url: 'https://cdn.example.com/products/eid.jpg',
    product_count: 12,
    display_order: 1,
    ...o,
  };
}

/** The en strings the card uses (mirrors public/i18n/en.json). */
const EN = {
  collections: {
    piece: 'piece',
    pieces: 'pieces',
    cardAria: '{{name}} collection, {{count}} pieces',
    cardAriaOne: '{{name}} collection, 1 piece',
  },
};

function render(collection: Collection | null): ComponentFixture<CollectionCardComponent> {
  TestBed.configureTestingModule({
    imports: [CollectionCardComponent],
    providers: [provideRouter([]), provideTranslateService({ fallbackLang: 'en', lang: 'en' })],
  });
  TestBed.inject(TranslateService).setTranslation('en', EN);
  const fixture = TestBed.createComponent(CollectionCardComponent);
  fixture.componentInstance.collection = collection;
  fixture.detectChanges();
  return fixture;
}

describe('CollectionCardComponent', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('links the whole card to /collection/<slug>', () => {
    const el: HTMLElement = render(makeCollection()).nativeElement;
    const link = el.querySelector<HTMLAnchorElement>('[data-testid="collection-card"]');
    expect(link).not.toBeNull();
    expect(link!.getAttribute('href')).toBe('/collection/eid-edit');
  });

  it('shows the name and piece count over the image background', () => {
    const el: HTMLElement = render(makeCollection()).nativeElement;
    expect(el.querySelector('.collection-card__name')?.textContent?.trim()).toBe('Eid Edit');
    expect(el.querySelector('.collection-card__count')?.textContent?.replace(/\s+/g, ' ').trim())
      .toBe('12 pieces');
    const bg = el.querySelector<HTMLImageElement>('[data-testid="collection-card-bg"]');
    expect(bg).not.toBeNull();
    expect(bg!.getAttribute('alt')).toBe('');
    expect(bg!.getAttribute('src')).toContain('eid.jpg');
    expect(el.querySelector('.collection-card--no-image')).toBeNull();
  });

  it('falls back to the brand gradient when there is no image', () => {
    const el: HTMLElement = render(makeCollection({ image_url: null })).nativeElement;
    expect(el.querySelector('[data-testid="collection-card-bg"]')).toBeNull();
    expect(el.querySelector('.collection-card--no-image')).not.toBeNull();
  });

  it('gives the link an accessible name (singular/plural aware)', () => {
    let el: HTMLElement = render(makeCollection()).nativeElement;
    expect(el.querySelector('[data-testid="collection-card"]')?.getAttribute('aria-label'))
      .toBe('Eid Edit collection, 12 pieces');
    TestBed.resetTestingModule();
    el = render(makeCollection({ product_count: 1 })).nativeElement;
    expect(el.querySelector('[data-testid="collection-card"]')?.getAttribute('aria-label'))
      .toBe('Eid Edit collection, 1 piece');
  });

  it('renders nothing for a null collection', () => {
    const el: HTMLElement = render(null).nativeElement;
    expect(el.querySelector('[data-testid="collection-card"]')).toBeNull();
  });
});
