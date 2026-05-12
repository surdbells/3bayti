// File: store_rating.ts

import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-store-rating',
  template: `
    <div class="store-box">
      <div class="name">{{ name }}</div>
      <div class="rating">⭐ {{ rating }} ({{ ratingsCount }} ratings)</div>
    </div>
  `,
  standalone: true,
  styles: [``]
})
export class StoreRatingSimpleComponent {
  @Input() name: string = 'Store Name';
  @Input() rating: number = 0;
  @Input() ratingsCount: number | string = '0+';
}
