import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { NgFor, NgClass } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * CheckoutStepperComponent — 3-step progress indicator.
 *
 * Renders three circle+label pairs joined by connector lines:
 *   1. Shipping  2. Review  3. Payment
 *
 * Steps before activeStep are 'done' (filled brand colour), the
 * activeStep is 'active' (outlined brand colour), steps after are
 * 'upcoming' (neutral).
 *
 * a11y
 * ----
 * Rendered as <ol> with explicit aria-current="step" on the active
 * step. Each step number is decorative; the label is the
 * accessible name.
 */
@Component({
  selector: 'app-checkout-stepper',
  standalone: true,
  imports: [NgFor, NgClass, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ol class="checkout-stepper" [attr.aria-label]="'checkout.progressAria' | translate">
      <li
        *ngFor="let step of steps; let i = index"
        class="checkout-stepper__item"
        [ngClass]="{
          'checkout-stepper__item--done': i < activeStep,
          'checkout-stepper__item--active': i === activeStep,
          'checkout-stepper__item--upcoming': i > activeStep
        }"
        [attr.aria-current]="i === activeStep ? 'step' : null"
        [attr.data-testid]="'checkout-step-' + (i + 1)"
      >
        <span class="checkout-stepper__num" aria-hidden="true">
          {{ i + 1 }}
        </span>
        <span class="checkout-stepper__label">
          {{ step | translate }}
        </span>
      </li>
    </ol>
  `,
  styles: [
    `
      .checkout-stepper {
        list-style: none;
        padding: 0;
        margin: 0 0 32px 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        counter-reset: step;
      }

      .checkout-stepper__item {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 0;
        position: relative;

        &:not(:last-child)::after {
          content: '';
          flex: 1;
          height: 1px;
          background: var(--color-border-default, #d8cfc1);
          margin-inline-start: 10px;
        }

        &--done {
          .checkout-stepper__num {
            background: var(--color-brand-500, #b18f1f);
            color: #fff;
            border-color: var(--color-brand-500, #b18f1f);
          }
          .checkout-stepper__label {
            color: var(--color-brand-700, #5a3a2c);
          }
          &::after {
            background: var(--color-brand-500, #b18f1f);
          }
        }

        &--active {
          .checkout-stepper__num {
            background: #fff;
            color: var(--color-brand-500, #b18f1f);
            border-color: var(--color-brand-500, #b18f1f);
          }
          .checkout-stepper__label {
            color: var(--color-text-primary, #2e241c);
            font-weight: 600;
          }
        }

        &--upcoming {
          .checkout-stepper__num {
            background: #fff;
            color: var(--color-text-muted, #6b6056);
            border-color: var(--color-border-default, #d8cfc1);
          }
          .checkout-stepper__label {
            color: var(--color-text-muted, #6b6056);
          }
        }
      }

      .checkout-stepper__num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: 2px solid;
        font-size: 13px;
        font-weight: 600;
        flex-shrink: 0;
        font-variant-numeric: tabular-nums;
      }

      .checkout-stepper__label {
        font-size: 13px;
        letter-spacing: 0.02em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      @media (max-width: 560px) {
        .checkout-stepper__label {
          display: none;
        }
        .checkout-stepper__item:not(:last-child)::after {
          margin-inline-start: 0;
        }
      }
    `,
  ],
})
export class CheckoutStepperComponent {
  /** Zero-indexed active step: 0=address, 1=review, 2=payment. */
  @Input({ required: true }) activeStep!: number;

  protected readonly steps: readonly string[] = [
    'checkout.steps.shipping',
    'checkout.steps.review',
    'checkout.steps.payment',
  ];
}
