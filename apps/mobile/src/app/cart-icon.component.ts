import {Component, Input, OnInit} from '@angular/core';

import { IonicModule } from '@ionic/angular';
import { AxIconComponent } from './shared/ax-mobile/icon';
import {Preferences} from "@capacitor/preferences";

@Component({
  selector: 'app-cart-icon',
  standalone: true,
  imports: [IonicModule, AxIconComponent],
  template: `<ax-icon name="shopping-cart" [style.color]="'var(--text)'" aria-hidden="true"></ax-icon>
            @if (count && count > 0) {
              <span class="cart-badge" [attr.aria-label]="count + ' items in cart'">
                {{ displayCount }}
              </span>
            }`,
  styles: [`
    /* badge */
    .cart-badge {
      position: absolute;
      top: 0;      /* tweak to move up/down */
      right: 2px;    /* tweak to move left/right */
      transform: translate(25%, -25%);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 18px;
      height: 18px;
      font-size: 11px;
      line-height: 1;
      border-radius: 999px;
      background: var(--ion-color-danger, #ef4444);
      color: #fff;
      font-weight: 600;
      box-shadow: 0 1px 2px rgba(0,0,0,0.25);
      pointer-events: none; /* lets clicks pass through to the button */
    }
    /* smaller screens / larger numbers adjustment */
    @media (max-width: 420px) {
      .cart-badge { font-size: 10px; min-width: 16px; height: 16px; padding: 0 5px; }
    }
  `]
})
export class CartIconComponent {
  /** number of items in cart */
  @Input() count = 3;
  /** Maximum number to show before displaying 'max+' */
  @Input() maxDisplay = 99;
  get displayCount(): string {
    if (!this.count || this.count <= 0) return '';
    return this.count > this.maxDisplay ? `${this.maxDisplay}+` : `${this.count}`;
  }
}
