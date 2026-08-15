import { Component, EventEmitter, Input, OnInit, Output, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { PermissionService } from '../../services/permission.service';
import { AxCanDirective } from '../../shared/security/ax-can.directive';

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

  constructor(
    private router: Router,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false
  };

  /**
   * Per-section collapse state, keyed by the section id used in the template.
   * Persisted to localStorage so a user's expanded/collapsed layout survives
   * navigation and reloads (the sidenav re-instantiates on shell changes).
   */
  collapsed: Record<string, boolean> = {};
  private readonly COLLAPSE_KEY = 'ax_nav_collapsed';

  private loadCollapseState(): void {
    try {
      const raw = localStorage.getItem(this.COLLAPSE_KEY);
      this.collapsed = raw ? JSON.parse(raw) : {};
    } catch {
      this.collapsed = {};
    }
  }

  /** Toggle a section open/closed and persist the new layout. */
  toggleSection(key: string): void {
    this.collapsed[key] = !this.collapsed[key];
    try {
      localStorage.setItem(this.COLLAPSE_KEY, JSON.stringify(this.collapsed));
    } catch {
      /* storage unavailable — collapse still works for this session */
    }
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
    this.loadCollapseState();
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    // Ensure effective permissions are available to drive admin-menu gating
    // (idempotent; the admin shell also loads, this covers any standalone use).
    if (this.user_session?.is_admin || this.user_session?.is_finance || this.user_session?.is_support) {
      this.perms.load();
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
