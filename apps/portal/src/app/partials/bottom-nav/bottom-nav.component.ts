import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { AxDrawerService } from '../../shared/overlays/ax-drawer.service';
import { MoreSheetComponent } from './more-sheet.component';

/**
 * Bottom navigation for vendor users on mobile (≤ 768px).
 *
 * Five fixed slots: Dashboard, Products, Orders, Coupons, More.
 * The "More" slot opens a curated bottom sheet with secondary routes
 * (Settings, Notifications, Messages, Reviews, Returns, Compliance,
 * Profile, Security, Sign Out).
 *
 * Hidden ≥ 769 px via CSS — desktop users have the full sidenav.
 */
@Component({
  selector: 'app-bottom-nav',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive],
  template: `
    <nav class="ax-bottom-nav" aria-label="Primary navigation">
      <a
        routerLink="/account"
        routerLinkActive="ax-bottom-nav-item-active"
        [routerLinkActiveOptions]="{ exact: true }"
        class="ax-bottom-nav-item"
        aria-label="Dashboard">
        <span class="material-symbols-outlined" aria-hidden="true">dashboard</span>
        <span class="ax-bottom-nav-label">Home</span>
      </a>

      <a
        routerLink="/products"
        routerLinkActive="ax-bottom-nav-item-active"
        class="ax-bottom-nav-item"
        aria-label="Products">
        <span class="material-symbols-outlined" aria-hidden="true">inventory_2</span>
        <span class="ax-bottom-nav-label">Products</span>
      </a>

      <a
        routerLink="/orders"
        routerLinkActive="ax-bottom-nav-item-active"
        class="ax-bottom-nav-item"
        aria-label="Orders">
        <span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>
        <span class="ax-bottom-nav-label">Orders</span>
      </a>

      <a
        routerLink="/coupons"
        routerLinkActive="ax-bottom-nav-item-active"
        class="ax-bottom-nav-item"
        aria-label="Coupons">
        <span class="material-symbols-outlined" aria-hidden="true">local_offer</span>
        <span class="ax-bottom-nav-label">Coupons</span>
      </a>

      <button
        type="button"
        class="ax-bottom-nav-item"
        (click)="openMoreSheet()"
        aria-label="More options">
        <span class="material-symbols-outlined" aria-hidden="true">menu</span>
        <span class="ax-bottom-nav-label">More</span>
      </button>
    </nav>
  `,
  styleUrl: './bottom-nav.component.css',
})
export class BottomNavComponent {
  private readonly drawer = inject(AxDrawerService);

  openMoreSheet(): void {
    this.drawer.open(MoreSheetComponent, {
      position: 'bottom',
      ariaLabel: 'More navigation options',
    });
  }
}
