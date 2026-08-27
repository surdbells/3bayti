import { Component, EventEmitter, HostListener, OnDestroy, OnInit, Output } from '@angular/core';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { CommonModule } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { Subject, Observable, of, forkJoin } from 'rxjs';
import { debounceTime, distinctUntilChanged, switchMap, map, catchError, takeUntil } from 'rxjs/operators';
import { Notifications } from '../../class/notifications';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { PermissionService } from '../../services/permission.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';

import { IconComponent } from '../../shared/icon/icon.component';
import { apiErrorMessage } from '../../shared/http/api-error';

/** A single hit in the global search dropdown. */
interface SearchResultItem {
  label: string;
  sublabel: string;
  icon: string;
  commands: unknown[];
  queryParams?: Record<string, unknown>;
}

/** A titled group of hits (Orders / Products / Stores / Customers). */
interface SearchResultGroup {
  key: string;
  label: string;
  items: SearchResultItem[];
}

@Component({
  selector: 'app-admin-top',
  standalone: true,
  imports: [LanguageSwitcherComponent, RouterLink, CommonModule, IconComponent],
  templateUrl: './admin-top.component.html',
  styleUrl: './admin-top.component.css'
})
export class AdminTopComponent implements OnInit, OnDestroy {
  notifications?: Notifications[];

  /** Emitted when the user taps the mobile menu button. Parent wires it to the sidebar. */
  @Output() menuToggle = new EventEmitter<void>();

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private perms: PermissionService,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    count: 0,
    notif_open: false,
    profile_open: false
  };

  /** Global "search the platform" state (header). */
  search = {
    query: '',
    open: false,
    loading: false,
    groups: [] as SearchResultGroup[],
  };

  private readonly searchInput$ = new Subject<string>();
  private readonly destroy$ = new Subject<void>();

  session_data: any = '';
  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    avatar: '',
    phone: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  };

  notification = { id: 0, token: '' };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.notification.id = this.user_session.id;
    this.notification.token = this.user_session.token;
    this.get_notifications();

    // Debounced global search, one keystroke stream, cancels stale requests.
    this.perms.load();
    this.searchInput$
      .pipe(
        debounceTime(300),
        distinctUntilChanged(),
        switchMap((term) => this.runSearch(term)),
        takeUntil(this.destroy$),
      )
      .subscribe((groups) => {
        this.search.groups = groups;
        this.search.loading = false;
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  // ===== Global search =====

  onSearchInput(value: string): void {
    this.search.query = value;
    const term = value.trim();
    if (term.length < 2) {
      this.search.groups = [];
      this.search.open = false;
      this.search.loading = false;
      return;
    }
    this.search.open = true;
    this.search.loading = true;
    this.searchInput$.next(term);
  }

  /** Re-open the dropdown on focus if there's a live query to show. */
  onSearchFocus(): void {
    if (this.search.query.trim().length >= 2) {
      this.search.open = true;
    }
  }

  /** Enter opens the first hit; a quick way to jump to the top match. */
  submitSearch(): void {
    const first = this.search.groups[0]?.items[0];
    if (first) {
      this.goToResult(first);
    }
  }

  goToResult(item: SearchResultItem): void {
    this.closeSearch();
    this.router.navigate(item.commands, item.queryParams ? { queryParams: item.queryParams } : undefined);
  }

  closeSearch(): void {
    this.search.open = false;
  }

  /**
   * Fan out the term across the entity endpoints the user is allowed to see.
   * Each source is independent: a failing/forbidden one resolves to null and is
   * dropped, so partial results still render. Empty groups are filtered out.
   */
  private runSearch(term: string): Observable<SearchResultGroup[]> {
    const t = term.trim();
    if (t.length < 2) {
      return of([]);
    }
    const limit = 5;
    const sources: Observable<SearchResultGroup | null>[] = [];

    if (this.perms.can('orders.view')) {
      sources.push(
        this.adapter.get_v3('GET /admin/orders', { query: { search: t, limit } }).pipe(
          map((r: any) => this.toGroup('orders', 'Orders', this.pickArray(r, ['orders', 'data', 'items']).map((o: any): SearchResultItem => ({
            label: o.order_reference ?? `#${o.id}`,
            sublabel: this.personName(o.customer) || (o.total != null ? `AED ${o.total}` : ''),
            icon: 'receipt_long',
            commands: ['/admin/orders', o.id],
          })))),
          catchError(() => of(null)),
        ),
      );
    }

    if (this.perms.can('products.view')) {
      sources.push(
        this.adapter.get_v3('GET /admin/products', { query: { search: t, limit } }).pipe(
          map((r: any) => this.toGroup('products', 'Products', this.pickArray(r, ['products', 'data', 'items']).map((p: any): SearchResultItem => ({
            label: p.name ?? p.title ?? p.product_name ?? `#${p.id}`,
            sublabel: p.vendor_name ?? p.vendor?.store_name ?? '',
            icon: 'shopping_bag',
            commands: ['/admin/products', p.id],
          })))),
          catchError(() => of(null)),
        ),
      );
    }

    if (this.perms.can('vendors.view')) {
      sources.push(
        this.adapter.get_v3('GET /admin/vendors', { query: { search: t, limit } }).pipe(
          map((r: any) => this.toGroup('vendors', 'Stores', this.pickArray(r, ['vendors', 'data', 'items']).map((v: any): SearchResultItem => ({
            label: v.store_name ?? v.name ?? v.business_name ?? `#${v.id}`,
            sublabel: v.email ?? v.city ?? '',
            icon: 'storefront',
            commands: ['/admin/stores', v.id],
            queryParams: { name: v.store_name ?? v.name ?? '' },
          })))),
          catchError(() => of(null)),
        ),
      );
    }

    // Customers are user records (role=customer); the customers page gates on
    // orders.view since there's no dedicated customers.* permission key.
    if (this.perms.can('orders.view')) {
      sources.push(
        this.adapter.get_v3('GET /admin/users', { query: { search: t, role: 'customer', limit } }).pipe(
          map((r: any) => this.toGroup('customers', 'Customers', this.pickArray(r, ['data', 'items', 'users']).map((u: any): SearchResultItem => ({
            label: `${u.first_name ?? ''} ${u.last_name ?? ''}`.trim() || (u.email ?? `#${u.id}`),
            sublabel: u.email ?? u.phone ?? '',
            icon: 'person',
            commands: ['/admin/customers', u.id],
          })))),
          catchError(() => of(null)),
        ),
      );
    }

    if (sources.length === 0) {
      return of([]);
    }
    return forkJoin(sources).pipe(
      map((groups) => groups.filter((g): g is SearchResultGroup => !!g && g.items.length > 0)),
    );
  }

  /** First array found under any of the candidate keys (tolerates envelope shapes). */
  private pickArray(res: any, keys: string[]): any[] {
    for (const k of keys) {
      const v = res?.[k];
      if (Array.isArray(v)) {
        return v;
      }
      if (Array.isArray(v?.items)) {
        return v.items;
      }
    }
    return Array.isArray(res) ? res : [];
  }

  private personName(c: any): string {
    if (!c) {
      return '';
    }
    return `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim();
  }

  private toGroup(key: string, label: string, items: SearchResultItem[]): SearchResultGroup {
    return { key, label, items };
  }

  // ===== Dropdowns =====

  toggle_notif(): void {
    this.ui_controls.notif_open = !this.ui_controls.notif_open;
    this.ui_controls.profile_open = false;
    this.closeSearch();
    if (this.ui_controls.notif_open) {
      this.get_notifications();
    }
  }

  toggle_profile(): void {
    this.ui_controls.profile_open = !this.ui_controls.profile_open;
    this.ui_controls.notif_open = false;
    this.closeSearch();
  }

  close_all(): void {
    this.ui_controls.notif_open = false;
    this.ui_controls.profile_open = false;
    this.search.open = false;
  }

  @HostListener('document:click')
  onDocumentClick(): void {
    this.close_all();
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.close_all();
  }

  get_notifications() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /admin/notifications')
      .subscribe({
        next: (response: any) => {
          this.ui_controls.is_loading = false;
          this.notifications = response?.data ?? [];
          this.ui_controls.count = response?.meta?.unread ?? 0;
        },
        error: (e: any) => {
          console.error(e);
          this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
          this.ui_controls.is_loading = false;
        }
      });
  }

  mark_notifications() {
    this.ui_controls.is_loading = true;
    this.adapter.post_v3('POST /admin/notifications/mark-read', {})
      .subscribe({
        next: () => {
          this.ui_controls.is_loading = false;
          this.get_notifications();
        },
        error: (e: any) => {
          console.error(e);
          this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
          this.ui_controls.is_loading = false;
        }
      });
  }

  sign_out(): void {
    localStorage.clear();
    sessionStorage.clear();
    this.success_notification('User logged out successfully.');
    this.router.navigate(['/']).then(r => console.log(r));
  }
}
