import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ProductStripComponent } from './product-strip';
import { provideI18n } from '../../core/i18n';

function setup(inputs: Record<string, unknown> = {}): {
  fixture: ComponentFixture<ProductStripComponent>;
  el: HTMLElement;
} {
  TestBed.configureTestingModule({
    imports: [ProductStripComponent],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideI18n()],
  });
  const fixture = TestBed.createComponent(ProductStripComponent);
  fixture.componentRef.setInput('heading', 'Best sellers');
  for (const [k, v] of Object.entries(inputs)) fixture.componentRef.setInput(k, v);
  fixture.detectChanges();
  return { fixture, el: fixture.nativeElement as HTMLElement };
}

describe('ProductStripComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the section heading', () => {
    const { el } = setup();
    expect(el.querySelector('.strip__title')?.textContent).toContain('Best sellers');
  });

  it('uses the default (non-compact) density by default', () => {
    const { el } = setup();
    expect(el.querySelector('.strip')!.classList.contains('strip--compact')).toBe(false);
  });

  it('applies the compact density class when [compact]="true"', () => {
    const { el } = setup({ compact: true });
    expect(el.querySelector('.strip')!.classList.contains('strip--compact')).toBe(true);
  });
});
