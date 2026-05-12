import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { AxDrawerRef } from '../../shared/overlays/ax-drawer.service';
import { TranslatePipe } from '../../translate.pipe';

interface MoreSheetLink {
  route: string;
  icon: string;
  labelKey: string;
}

interface MoreSheetSection {
  titleKey: string;
  links: MoreSheetLink[];
}

/**
 * Curated "More" menu rendered as a bottom sheet on mobile.
 *
 * Mirrors the secondary routes from the sidenav — i.e. everything that ISN'T
 * already in the bottom nav (Dashboard, Products, Orders, Coupons live there).
 */
@Component({
  selector: 'app-more-sheet',
  standalone: true,
  imports: [CommonModule, RouterLink, RouterLinkActive, TranslatePipe],
  template: `
    <div class="ax-drawer-header">
      <div class="ax-drawer-header-content">
        <h2 class="ax-drawer-title">{{ 'more' | translate }}</h2>
      </div>
      <button
        type="button"
        class="ax-drawer-close"
        (click)="close()"
        aria-label="Close menu">
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </div>

    <div class="ax-drawer-body more-sheet-body">
      <div *ngFor="let section of sections" class="more-sheet-section">
        <p class="more-sheet-section-title">{{ section.titleKey | translate }}</p>
        <ul class="more-sheet-list">
          <li *ngFor="let link of section.links">
            <a
              [routerLink]="link.route"
              routerLinkActive="more-sheet-item-active"
              class="more-sheet-item"
              (click)="close()">
              <span class="material-symbols-outlined more-sheet-icon" aria-hidden="true">{{ link.icon }}</span>
              <span class="more-sheet-item-label">{{ link.labelKey | translate }}</span>
              <span class="material-symbols-outlined more-sheet-chevron" aria-hidden="true">chevron_right</span>
            </a>
          </li>
        </ul>
      </div>

      <div class="more-sheet-section">
        <button
          type="button"
          class="more-sheet-item more-sheet-item-danger"
          (click)="signOut()">
          <span class="material-symbols-outlined more-sheet-icon" aria-hidden="true">logout</span>
          <span class="more-sheet-item-label">{{ 'sign_out' | translate }}</span>
        </button>
      </div>
    </div>
  `,
  styleUrl: './more-sheet.component.css',
})
export class MoreSheetComponent {
  private readonly drawerRef = inject<AxDrawerRef<MoreSheetComponent>>(AxDrawerRef);
  private readonly router = inject(Router);

  readonly sections: MoreSheetSection[] = [
    {
      titleKey: 'main_menu',
      links: [
        { route: '/delivery', icon: 'local_shipping', labelKey: 'delivery_list' },
        { route: '/returns', icon: 'assignment_return', labelKey: 'process_returns' },
        { route: '/reviews', icon: 'reviews', labelKey: 'product_reviews' },
        { route: '/messages', icon: 'chat', labelKey: 'messages' },
      ],
    },
    {
      titleKey: 'store_settings',
      links: [
        { route: '/store', icon: 'store', labelKey: 'manage_store' },
        { route: '/payment_info', icon: 'credit_card', labelKey: 'payment_information' },
        { route: '/tax_information', icon: 'receipt', labelKey: 'tax_information' },
        { route: '/labels', icon: 'label', labelKey: 'product_labels' },
        { route: '/measurements', icon: 'straighten', labelKey: 'measurements' },
      ],
    },
    {
      titleKey: 'account',
      links: [
        { route: '/profile', icon: 'person', labelKey: 'user_profile' },
        { route: '/security', icon: 'lock', labelKey: 'security' },
        { route: '/notifications', icon: 'notifications', labelKey: 'notifications' },
        { route: '/compliance', icon: 'policy', labelKey: 'compliance' },
      ],
    },
  ];

  close(): void {
    this.drawerRef.close();
  }

  signOut(): void {
    this.close();
    localStorage.clear();
    sessionStorage.clear();
    this.router.navigate(['/']).then(r => console.log(r));
  }
}
