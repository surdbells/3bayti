import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, Subscription, firstValueFrom, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, map, switchMap } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxMultiselectComponent, type AxMultiselectOption } from '../../shared/forms/ax-multiselect.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { loadAdminVendorOptions } from '../shared/order-filters';
import {
  type CashbackRule,
  type CashbackRuleForm,
  type CashbackScope,
  type CashbackTargetType,
  CASHBACK_CHANNELS,
  CASHBACK_SCOPES,
  emptyRuleForm,
  flattenCategories,
  formToPayload,
  parseRule,
  parseSettings,
  ruleToForm,
  serverFieldErrors,
  unwrap,
  validateRuleForm,
} from './cashback-ui';

/**
 * Cashback rule create / edit.
 *
 * Create (no ?id) → POST /admin/cashback/rules. Edit (?id=12) → GET to
 * prefill, PUT to save. `targets` is always sent and REPLACES the rule's
 * whole target set: inclusions of the scope's own type plus exclusions of
 * any type (stores / products / categories), all by v3 id.
 */
@Component({
  selector: 'app-cashback-rule-form',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, AxMultiselectComponent, TranslatePipe],
  templateUrl: './cashback-rule-form.component.html',
  styleUrl: './cashback-rule-form.component.css',
})
export class CashbackRuleFormComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly i18n = inject(I18nService);

  readonly scopes = CASHBACK_SCOPES;
  readonly channels = CASHBACK_CHANNELS;

  id: number | null = null;
  rule: CashbackRule | null = null;
  ui = { loading: false, saving: false };
  form: CashbackRuleForm = emptyRuleForm();
  errors: Record<string, string> = {};

  /** id → label per target type. Options are derived from these maps so a
   *  selected id keeps its chip label even when it isn't in a search page. */
  private readonly labels: Record<CashbackTargetType, Map<number, string>> = {
    vendor: new Map(),
    product: new Map(),
    category: new Map(),
  };
  vendorOptions: AxMultiselectOption[] = [];
  productOptions: AxMultiselectOption[] = [];
  categoryOptions: AxMultiselectOption[] = [];

  productSearch = '';
  productSearching = false;
  private readonly productSearch$ = new Subject<string>();
  private productSub?: Subscription;

  get isEdit(): boolean {
    return this.id !== null;
  }

  t(key: string, params?: Record<string, unknown>): string {
    return this.i18n.t(key, params);
  }

  ngOnInit(): void {
    const raw = this.route.snapshot.queryParamMap.get('id');
    const parsed = raw != null ? Number(raw) : NaN;
    if (Number.isInteger(parsed) && parsed > 0) {
      this.id = parsed;
      this.load(parsed);
    } else {
      this.prefillDefaults();
    }
    this.loadVendors();
    this.loadCategories();
    this.productSub = this.productSearch$
      .pipe(
        debounceTime(300),
        distinctUntilChanged(),
        switchMap((term) => {
          this.productSearching = true;
          return this.fetchProducts(term);
        }),
      )
      .subscribe((items) => {
        this.productSearching = false;
        for (const p of items) this.labels.product.set(p.id, p.label);
        this.rebuildOptions('product', items.map((p) => p.id));
      });
    this.productSearch$.next('');
  }

  ngOnDestroy(): void {
    this.productSub?.unsubscribe();
  }

  // ── Loading ───────────────────────────────────────────────────────────

  /** New rule: seed hold / validity days from the programme defaults. */
  private prefillDefaults(): void {
    this.adapter.get_v3('GET /admin/cashback/settings').subscribe({
      next: (res: any) => {
        if (this.isEdit) return;
        const s = parseSettings(unwrap(res));
        this.form.hold_days = s.default_hold_days;
        this.form.gift_card_validity_days = s.default_validity_days;
      },
      error: () => { /* keep the built-in 14 / 365 defaults */ },
    });
  }

  private load(id: number): void {
    this.ui.loading = true;
    this.adapter.get_v3('GET /admin/cashback/rules/:id', { params: { id: String(id) } }).subscribe({
      next: (res: any) => {
        this.rule = parseRule(unwrap(res));
        for (const t of this.rule.targets) {
          if (t.label) this.labels[t.target_type].set(t.target_id, t.label);
          else if (!this.labels[t.target_type].has(t.target_id)) this.labels[t.target_type].set(t.target_id, `#${t.target_id}`);
        }
        this.form = ruleToForm(this.rule);
        this.rebuildAllOptions();
        this.ui.loading = false;
      },
      error: (err: any) => {
        this.ui.loading = false;
        this.toast.error(apiErrorMessage(err, this.t('cashback.form.load_error')));
        this.goBack();
      },
    });
  }

  private async loadVendors(): Promise<void> {
    const vendors = await loadAdminVendorOptions(this.adapter);
    for (const v of vendors) {
      const id = Number(v.value);
      if (id > 0) this.labels.vendor.set(id, v.label);
    }
    this.rebuildOptions('vendor');
  }

  private loadCategories(): void {
    this.adapter.get_v3('GET /utility/categories').subscribe({
      next: (res: any) => {
        const tree = Array.isArray(res?.data) ? res.data : Array.isArray(res) ? res : [];
        for (const c of flattenCategories(tree)) this.labels.category.set(c.id, c.label);
        this.rebuildOptions('category');
      },
      error: (err: any) => this.toast.error(apiErrorMessage(err, this.t('cashback.form.categories_error'))),
    });
  }

  private fetchProducts(term: string) {
    const query: Record<string, string | number> = { limit: 50 };
    if (term.trim()) query['search'] = term.trim();
    return this.adapter.get_v3('GET /admin/products', { query }).pipe(
      map((res: any) => {
        const raw: any[] = Array.isArray(res?.data) ? res.data : Array.isArray(res?.products) ? res.products : [];
        return raw
          .filter((p) => Number(p?.id) > 0)
          .map((p) => ({
            id: Number(p.id),
            label: p.vendor?.name ? `${p.name ?? `#${p.id}`} · ${p.vendor.name}` : String(p.name ?? `#${p.id}`),
          }));
      }),
      catchError(() => of([] as { id: number; label: string }[])),
    );
  }

  onProductSearch(term: string): void {
    this.productSearch = term;
    this.productSearch$.next(term);
  }

  private rebuildAllOptions(): void {
    this.rebuildOptions('vendor');
    this.rebuildOptions('product');
    this.rebuildOptions('category');
  }

  /**
   * Options = every selected id (so chips keep labels) + either the whole
   * label map (stores / categories) or the latest product search page.
   */
  private rebuildOptions(type: CashbackTargetType, visibleIds?: number[]): void {
    const labels = this.labels[type];
    const selected = this.selectedIds(type);
    const ids: number[] = [...selected];
    const pool = type === 'product'
      ? (visibleIds ?? this.productOptions.map((o) => Number(o.id)))
      : [...labels.keys()];
    for (const id of pool) if (!ids.includes(id)) ids.push(id);
    const opts = ids.map((id) => ({ id, label: labels.get(id) ?? `#${id}` }));
    if (type !== 'product') opts.sort((a, b) => a.label.localeCompare(b.label));
    if (type === 'vendor') this.vendorOptions = opts;
    else if (type === 'product') this.productOptions = opts;
    else this.categoryOptions = opts;
  }

  private selectedIds(type: CashbackTargetType): number[] {
    const f = this.form;
    const include = f.scope !== 'all' && this.includeType === type ? f.include_ids : [];
    const exclude = type === 'vendor' ? f.exclude_vendor_ids : type === 'product' ? f.exclude_product_ids : f.exclude_category_ids;
    return [...include, ...exclude].map(Number).filter((n) => n > 0);
  }

  // ── Scope ─────────────────────────────────────────────────────────────

  get includeType(): CashbackTargetType | null {
    switch (this.form.scope) {
      case 'stores': return 'vendor';
      case 'products': return 'product';
      case 'categories': return 'category';
      default: return null;
    }
  }

  get hasExclusions(): boolean {
    const f = this.form;
    return f.exclude_vendor_ids.length + f.exclude_product_ids.length + f.exclude_category_ids.length > 0;
  }

  includeOptions(): AxMultiselectOption[] {
    switch (this.includeType) {
      case 'vendor': return this.vendorOptions;
      case 'product': return this.productOptions;
      case 'category': return this.categoryOptions;
      default: return [];
    }
  }

  setScope(scope: CashbackScope): void {
    if (this.form.scope === scope) return;
    this.form.scope = scope;
    this.form.include_ids = [];
    delete this.errors['targets'];
  }

  /** Keep options in sync after a selection so a product chosen from a
   *  search page keeps its label when the search changes. */
  onSelectionChanged(type: CashbackTargetType): void {
    this.rebuildOptions(type);
    delete this.errors['targets'];
  }

  // ── Save ──────────────────────────────────────────────────────────────

  errText(field: string): string {
    const msg = this.errors[field];
    if (!msg) return '';
    return msg.startsWith('cashback.') ? this.t(msg) : msg;
  }

  save(): void {
    if (this.ui.saving) return;
    this.errors = validateRuleForm(this.form);
    if (Object.keys(this.errors).length) {
      this.toast.error(this.t('cashback.form.fix_errors'));
      this.focusFirstError();
      return;
    }
    const body = formToPayload(this.form);
    this.ui.saving = true;
    const req = this.isEdit
      ? this.adapter.put_v3('PUT /admin/cashback/rules/:id', body, { params: { id: String(this.id) } })
      : this.adapter.post_v3('POST /admin/cashback/rules', body);

    firstValueFrom(req)
      .then(() => {
        this.ui.saving = false;
        this.toast.success(this.t(this.isEdit ? 'cashback.form.updated' : 'cashback.form.created'));
        this.router.navigate(['/admin/cashback']);
      })
      .catch((err: any) => {
        this.ui.saving = false;
        const fields = serverFieldErrors(err);
        if (Object.keys(fields).length) {
          this.errors = fields;
          this.focusFirstError();
        }
        this.toast.error(apiErrorMessage(err, this.t(this.isEdit ? 'cashback.form.update_error' : 'cashback.form.create_error')));
      });
  }

  private focusFirstError(): void {
    const first = Object.keys(this.errors)[0];
    if (!first) return;
    setTimeout(() => {
      const el = document.getElementById(`cb-${first}`);
      el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) el.focus();
    });
  }

  goBack(): void {
    this.navHistory.back('/admin/cashback');
  }
}
