import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, Subscription } from 'rxjs';
import { debounceTime, distinctUntilChanged } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AxComboboxComponent, AxComboboxOption } from '../../../shared/forms/ax-combobox.component';

interface ItemRow {
  product_id: number;
  name: string;
  image: string | null;
  price: number | null;
  discount_percent: number | null;
  stock_total: number | null;
  stock_remaining: number | null;
}

interface ProductResult {
  id: number;
  name: string;
  image: string | null;
  price: number | null;
}

/**
 * Create OR edit a campaign (mode detected from the ?id query param).
 * Handles the campaign fields plus its product line-up, with per-item
 * discount + stock and a live product search to add items.
 */
@Component({
  selector: 'app-campaign-form',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, AxComboboxComponent],
  templateUrl: './campaign-form.component.html',
  styleUrl: './campaign-form.component.css',
})
export class CampaignFormComponent implements OnInit, OnDestroy {
  readonly typeOptions: AxComboboxOption[] = [
    { id: 'anniversary', label: 'Anniversary — celebratory deals' },
    { id: 'flash', label: 'Flash Sale — urgency + stock bars' },
  ];
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  ui = { is_loading: false, is_saving: false, searching: false };

  isEdit = false;
  campaignId = 0;

  form = {
    type: 'anniversary',
    title: '',
    subtitle: '',
    discount_percent: 10,
    starts_at: '',
    ends_at: '',
    is_active: true,
    priority: null as number | null,
  };

  items: ItemRow[] = [];

  productQuery = '';
  searchResults: ProductResult[] = [];
  private search$ = new Subject<string>();
  private sub = new Subscription();

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');

    const id = this.route.snapshot.queryParamMap.get('id');
    if (id) {
      this.isEdit = true;
      this.campaignId = Number(id);
      this.loadCampaign();
    } else {
      // sensible default window: starts now, ends in 7 days
      const now = new Date();
      const week = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
      this.form.starts_at = this.toLocalInput(now.toISOString());
      this.form.ends_at = this.toLocalInput(week.toISOString());
    }

    this.sub.add(
      this.search$.pipe(debounceTime(300), distinctUntilChanged()).subscribe((term) => this.runSearch(term)),
    );
  }

  ngOnDestroy() {
    this.sub.unsubscribe();
  }

  // ── load (edit) ──────────────────────────────────────────────────
  private loadCampaign() {
    this.ui.is_loading = true;
    this.adapter.get_v3('GET /admin/campaigns/:id', { params: { id: String(this.campaignId) } }).subscribe({
      next: (res: any) => {
        const c = res?.data ?? res;
        this.form = {
          type: c.type ?? 'anniversary',
          title: c.title ?? '',
          subtitle: c.subtitle ?? '',
          discount_percent: c.discount_percent ?? 0,
          starts_at: this.toLocalInput(c.starts_at),
          ends_at: this.toLocalInput(c.ends_at),
          is_active: c.is_active ?? true,
          priority: c.priority ?? null,
        };
        this.items = Array.isArray(c.items) ? c.items.map((it: any) => this.itemFromApi(it)) : [];
        this.ui.is_loading = false;
      },
      error: () => {
        this.toast.error('Unable to load this campaign.');
        this.ui.is_loading = false;
        this.router.navigate(['/campaigns']);
      },
    });
  }

  private itemFromApi(it: any): ItemRow {
    const p = it.product ?? {};
    return {
      product_id: p.id ?? it.product_id,
      name: p.name ?? '—',
      image: this.imageOf(p),
      price: this.priceOf(p),
      discount_percent: it.discount_percent ?? null,
      stock_total: it.stock_total ?? null,
      stock_remaining: it.stock_remaining ?? null,
    };
  }

  // ── product search ───────────────────────────────────────────────
  onSearchChange(term: string) {
    this.productQuery = term;
    if (term.trim().length < 2) {
      this.searchResults = [];
      return;
    }
    this.search$.next(term.trim());
  }

  private runSearch(term: string) {
    this.ui.searching = true;
    this.adapter.get_v3('GET /products', { query: { q: term, limit: 8 } }).subscribe({
      next: (res: any) => {
        const raw: any[] = Array.isArray(res?.data) ? res.data : res?.data?.items ?? [];
        this.searchResults = raw.map((p) => ({
          id: p.id,
          name: p.name ?? '—',
          image: this.imageOf(p),
          price: this.priceOf(p),
        }));
        this.ui.searching = false;
      },
      error: () => {
        this.ui.searching = false;
      },
    });
  }

  addProduct(p: ProductResult) {
    if (this.items.some((i) => i.product_id === p.id)) {
      this.toast.error('That product is already in this campaign.');
      return;
    }
    this.items.push({
      product_id: p.id,
      name: p.name,
      image: p.image,
      price: p.price,
      discount_percent: null,
      stock_total: null,
      stock_remaining: null,
    });
    this.productQuery = '';
    this.searchResults = [];
  }

  removeItem(index: number) {
    this.items.splice(index, 1);
  }

  // ── save ─────────────────────────────────────────────────────────
  save() {
    if (this.form.title.trim().length === 0) {
      this.toast.error('Campaign title is required.');
      return;
    }
    if (!this.form.starts_at || !this.form.ends_at) {
      this.toast.error('Start and end date/time are required.');
      return;
    }
    const startsIso = this.toIso(this.form.starts_at);
    const endsIso = this.toIso(this.form.ends_at);
    if (new Date(endsIso).getTime() <= new Date(startsIso).getTime()) {
      this.toast.error('End must be after start.');
      return;
    }

    const body = {
      type: this.form.type,
      title: this.form.title.trim(),
      subtitle: this.form.subtitle?.trim() || null,
      discount_percent: Number(this.form.discount_percent) || 0,
      starts_at: startsIso,
      ends_at: endsIso,
      is_active: this.form.is_active,
      priority: this.form.priority,
      items: this.items.map((it, idx) => ({
        product_id: it.product_id,
        discount_percent: it.discount_percent,
        stock_total: it.stock_total,
        stock_remaining: it.stock_remaining,
        sort_order: idx,
      })),
    };

    this.ui.is_saving = true;
    const req$ = this.isEdit
      ? this.adapter.put_v3('PUT /admin/campaigns/:id', body, { params: { id: String(this.campaignId) } })
      : this.adapter.post_v3('POST /admin/campaigns', body);

    req$.subscribe({
      next: () => {
        this.ui.is_saving = false;
        this.toast.success(this.isEdit ? 'Campaign updated.' : 'Campaign created.');
        this.router.navigate(['/campaigns']);
      },
      error: (e: any) => {
        console.error(e);
        this.ui.is_saving = false;
        this.toast.error(e?.error?.error?.message ?? 'Unable to save the campaign.');
      },
    });
  }

  goBack() {
    this.router.navigate(['/campaigns']);
  }

  // ── helpers ──────────────────────────────────────────────────────
  /**
   * Resolve a product's thumbnail URL across the shapes the API emits.
   * GET /v3/products (listShape) returns `primary_image` as an OBJECT
   * ({ url, alt, width, height }), NOT a string — binding that object
   * straight to [src] yields "[object Object]" and a broken image. The
   * vendor-manage shape uses a flat `image` string, and detail/list
   * shapes also carry an `images[]` gallery. Dig out a plain URL from
   * whichever is present.
   */
  private imageOf(p: any): string | null {
    const pi = p?.primary_image;
    if (typeof pi === 'string' && pi) return pi;
    if (pi && typeof pi === 'object' && pi.url) return pi.url;
    if (typeof p?.primary_image_url === 'string' && p.primary_image_url) return p.primary_image_url;
    if (typeof p?.image === 'string' && p.image) return p.image;
    const imgs = p?.images;
    if (Array.isArray(imgs) && imgs.length) {
      const first = imgs[0];
      if (typeof first === 'string') return first || null;
      if (first && typeof first === 'object' && first.url) return first.url;
    }
    return null;
  }

  private priceOf(p: any): number | null {
    const amt = p?.price?.amount ?? p?.price;
    return typeof amt === 'number' ? amt : (amt != null && !isNaN(Number(amt)) ? Number(amt) : null);
  }

  /** ISO 8601 → 'YYYY-MM-DDTHH:mm' in local time for datetime-local inputs. */
  private toLocalInput(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  /** datetime-local string (local time) → ISO 8601 (UTC). */
  private toIso(local: string): string {
    const d = new Date(local);
    return isNaN(d.getTime()) ? local : d.toISOString();
  }
}
