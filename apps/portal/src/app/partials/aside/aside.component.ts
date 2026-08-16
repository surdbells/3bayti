import { Component, EventEmitter, Input, OnInit, Output, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { PermissionService } from '../../services/permission.service';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import { NavBadgeService } from './nav-badge.service';

import { IconComponent } from '../../shared/icon/icon.component';
@Component({
  selector: 'app-aside',
  imports: [CommonModule, RouterLink, RouterLinkActive, IconComponent, AxCanDirective],
  standalone: true,
  templateUrl: './aside.component.html',
  styleUrl: './aside.component.css'
})
export class AsideComponent implements OnInit {
  /** Mobile drawer open state. Controlled by parent (topbar burger). */
  @Input() isOpen = false;
  @Output() isOpenChange = new EventEmitter<boolean>();

  /** Drives admin-menu visibility so users only see modules they can open. */
  protected readonly perms = inject(PermissionService);

  /** "New items" count badges (pending applications, new sales). */
  protected readonly navBadges = inject(NavBadgeService);

  constructor(
    private router: Router,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false
  };

  /**
   * Accordion state — the id of the single section that is currently open.
   * Exactly one section is open at a time; on load it defaults to the section
   * that contains the active route (falling back to the first section).
   */
  openSection = '';

  /** Path prefixes that belong to each section, for route-aware default-open. */
  private readonly SECTION_ROUTES: Record<string, string[]> = {
    'overview': ['/backend'],
    'store-ops': ['/products', '/orders', '/delivery', '/returns', '/reviews', '/messages', '/coupons', '/analytics', '/metrics'],
    'vendors-customers': ['/admin/stores', '/admin/vendor-applications', '/admin/customers'],
    'catalog': ['/admin/products', '/admin/collections', '/admin/campaigns', '/admin/gift-cards'],
    'orders-sales': ['/admin/orders', '/admin/sales', '/admin/returns', '/admin/commissions', '/admin/logistics'],
    'engagement': ['/admin/notifications', '/admin/notification-templates', '/admin/notification-schedules', '/admin/notification-broadcasts', '/admin/notification-logs'],
    'system': ['/admin/users', '/admin/ota'],
    'account': ['/profile', '/security', '/store', '/payment_info', '/tax_information'],
  };

  /** Open the section that owns the current route; else the first one open. */
  private initOpenSection(): void {
    const url = this.router.url.split('?')[0];
    let match = '';
    for (const [key, paths] of Object.entries(this.SECTION_ROUTES)) {
      if (paths.some(p => url === p || url.startsWith(p + '/'))) { match = key; break; }
    }
    this.openSection = match || (this.user_session?.is_vendor ? 'store-ops' : 'overview');
  }

  /**
   * Accordion toggle: open the clicked section and close the rest. Keeps one
   * section open at all times — re-clicking the open header leaves it open.
   */
  toggleSection(key: string): void {
    this.openSection = key;
  }
  session_data: any = '';
  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_finance: false,
    is_support: false,
    _sub_admin: false,
    is_customer: false
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.initOpenSection();
    // Ensure effective permissions are available to drive admin-menu gating
    // (idempotent; the admin shell also loads, this covers any standalone use).
    if (this.user_session?.is_admin || this.user_session?.is_finance || this.user_session?.is_support) {
      this.perms.load();
      this.navBadges.start();
    }
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  close(): void {
    this.isOpen = false;
    this.isOpenChange.emit(false);
  }

  open(): void {
    this.isOpen = true;
    this.isOpenChange.emit(true);
  }

  sign_out(): void {
    localStorage.clear();
    sessionStorage.clear();
    this.success_notification('User logged out successfully.');
    this.router.navigate(['/']).then(r => console.log(r));
  }
}
