import { describe, it, expect, afterEach } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { CheckoutStepperComponent } from './checkout-stepper';
import { provideI18n } from '../../core/i18n';

function setup(activeStep: number): {
  fixture: ComponentFixture<CheckoutStepperComponent>;
  controller: HttpTestingController;
} {
  TestBed.configureTestingModule({
    imports: [CheckoutStepperComponent],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideI18n()],
  });
  const fixture = TestBed.createComponent(CheckoutStepperComponent);
  fixture.componentInstance.activeStep = activeStep;
  fixture.detectChanges();
  return { fixture, controller: TestBed.inject(HttpTestingController) };
}

/** Flush any pending HTTP requests so unhandled-promise warnings don't
 *  fire after the test completes. provideI18n() triggers a /i18n/...json
 *  GET that we don't care about in these tests. */
function flushPending(controller: HttpTestingController): void {
  controller.match(() => true).forEach(req => {
    if (!req.cancelled) req.flush({});
  });
}

describe('CheckoutStepperComponent', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('renders 3 step items', () => {
    const { fixture, controller } = setup(0);
    const items = fixture.nativeElement.querySelectorAll('li');
    expect(items).toHaveLength(3);
    flushPending(controller);
  });

  it('marks step 1 as active when activeStep=0', () => {
    const { fixture, controller } = setup(0);
    const step1 = fixture.nativeElement.querySelector('[data-testid="checkout-step-1"]');
    const step2 = fixture.nativeElement.querySelector('[data-testid="checkout-step-2"]');
    expect(step1.classList.contains('checkout-stepper__item--active')).toBe(true);
    expect(step2.classList.contains('checkout-stepper__item--upcoming')).toBe(true);
    flushPending(controller);
  });

  it('marks step 1 as done and step 2 as active when activeStep=1', () => {
    const { fixture, controller } = setup(1);
    const step1 = fixture.nativeElement.querySelector('[data-testid="checkout-step-1"]');
    const step2 = fixture.nativeElement.querySelector('[data-testid="checkout-step-2"]');
    const step3 = fixture.nativeElement.querySelector('[data-testid="checkout-step-3"]');
    expect(step1.classList.contains('checkout-stepper__item--done')).toBe(true);
    expect(step2.classList.contains('checkout-stepper__item--active')).toBe(true);
    expect(step3.classList.contains('checkout-stepper__item--upcoming')).toBe(true);
    flushPending(controller);
  });

  it('marks steps 1+2 as done and step 3 as active when activeStep=2', () => {
    const { fixture, controller } = setup(2);
    const step1 = fixture.nativeElement.querySelector('[data-testid="checkout-step-1"]');
    const step2 = fixture.nativeElement.querySelector('[data-testid="checkout-step-2"]');
    const step3 = fixture.nativeElement.querySelector('[data-testid="checkout-step-3"]');
    expect(step1.classList.contains('checkout-stepper__item--done')).toBe(true);
    expect(step2.classList.contains('checkout-stepper__item--done')).toBe(true);
    expect(step3.classList.contains('checkout-stepper__item--active')).toBe(true);
    flushPending(controller);
  });

  it('sets aria-current=step on the active step only', () => {
    const { fixture, controller } = setup(1);
    const step1 = fixture.nativeElement.querySelector('[data-testid="checkout-step-1"]');
    const step2 = fixture.nativeElement.querySelector('[data-testid="checkout-step-2"]');
    expect(step1.getAttribute('aria-current')).toBeNull();
    expect(step2.getAttribute('aria-current')).toBe('step');
    flushPending(controller);
  });
});
