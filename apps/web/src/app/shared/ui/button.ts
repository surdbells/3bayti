import { Component, ChangeDetectionStrategy, Input, Output, EventEmitter } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf } from '@angular/common';

/**
 * Button — interactive primitive.
 *
 * Renders as <button> by default, or <a> when `href` or `routerLink` is
 * provided. This means semantic correctness (button-vs-link) is driven
 * by props instead of forcing the consumer to pick a different component.
 *
 * Variants:
 *   primary    Solid gold, white text — main CTAs
 *   secondary  Bordered, brand-colored text — secondary actions
 *   ghost      Transparent, brand-colored text — tertiary / inline
 *
 * Sizes:
 *   sm   32px tall  — compact / inline
 *   md   44px tall  — default (mobile-friendly tap target)
 *   lg   52px tall  — hero CTAs
 *
 * Usage:
 *   <ui-button (clicked)="onClick()">Buy now</ui-button>
 *   <ui-button variant="secondary" href="/about">About</ui-button>
 *   <ui-button variant="ghost" routerLink="/category/abayas">Browse</ui-button>
 *   <ui-button [disabled]="loading" size="lg">Sign up</ui-button>
 */
@Component({
  selector: 'ui-button',
  standalone: true,
  imports: [NgIf, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <a *ngIf="routerLink"
       [routerLink]="routerLink"
       class="ui-btn"
       [class]="'ui-btn--' + variant + ' ui-btn--' + size"
       [attr.aria-disabled]="disabled || null">
      <ng-content></ng-content>
    </a>
    <a *ngIf="href && !routerLink"
       [href]="href"
       class="ui-btn"
       [class]="'ui-btn--' + variant + ' ui-btn--' + size"
       [attr.aria-disabled]="disabled || null">
      <ng-content></ng-content>
    </a>
    <button *ngIf="!href && !routerLink"
            type="button"
            class="ui-btn"
            [class]="'ui-btn--' + variant + ' ui-btn--' + size"
            [disabled]="disabled"
            (click)="onClick($event)">
      <ng-content></ng-content>
    </button>
  `,
  styles: [`
    :host {
      display: inline-block;
    }

    .ui-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      border-radius: 999px;
      font-family: inherit;
      font-weight: 600;
      letter-spacing: 0.01em;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease,
                  border-color 0.15s ease, transform 0.05s ease;
      border: 1px solid transparent;
      -webkit-tap-highlight-color: transparent;
    }

    .ui-btn:active {
      transform: scale(0.98);
    }

    .ui-btn:disabled,
    .ui-btn[aria-disabled='true'] {
      opacity: 0.5;
      cursor: not-allowed;
      pointer-events: none;
    }

    /* ----- Sizes ------------------------------------------------------ */

    .ui-btn--sm {
      height: 32px;
      padding-inline: 14px;
      font-size: 13px;
    }
    .ui-btn--md {
      height: 44px;
      padding-inline: 22px;
      font-size: 14px;
    }
    .ui-btn--lg {
      height: 52px;
      padding-inline: 32px;
      font-size: 15px;
    }

    /* ----- Variants --------------------------------------------------- */

    .ui-btn--primary {
      background: var(--color-brand-700);
      color: var(--color-text-inverse);
    }
    .ui-btn--primary:hover,
    .ui-btn--primary:focus-visible {
      background: var(--color-brand-600);
    }

    .ui-btn--secondary {
      background: var(--color-bg-surface);
      color: var(--color-brand-700);
      border-color: var(--color-border-default);
    }
    .ui-btn--secondary:hover,
    .ui-btn--secondary:focus-visible {
      background: var(--color-bg-muted);
      border-color: var(--color-brand-700);
    }

    .ui-btn--ghost {
      background: transparent;
      color: var(--color-brand-700);
    }
    .ui-btn--ghost:hover,
    .ui-btn--ghost:focus-visible {
      background: var(--color-bg-muted);
    }
  `],
})
export class ButtonComponent {
  @Input() variant: 'primary' | 'secondary' | 'ghost' = 'primary';
  @Input() size: 'sm' | 'md' | 'lg' = 'md';
  @Input() disabled = false;
  @Input() href: string | null = null;
  @Input() routerLink: string | null = null;
  @Output() clicked = new EventEmitter<MouseEvent>();

  onClick(event: MouseEvent): void {
    if (this.disabled) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    this.clicked.emit(event);
  }
}
