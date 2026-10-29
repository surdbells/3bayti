import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Observable, Subject, of, throwError } from 'rxjs';

import { AdminTryOnComponent, TryOnRow, toTryOnRow } from './admin-try-on.component';
import {
  TryOnAdminService,
  TryOnBulkResult,
  TryOnProduct,
  TryOnProductPage,
  TryOnStatus,
} from '../../services/try-on-admin.service';
import { PermissionService } from '../../services/permission.service';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { I18nService } from '../../i18n.service';
import { AX_EMPTY_QUERY, type AxQueryState } from '../../shared/data/enterprise';

const product = (id: number, patch: Partial<TryOnProduct> = {}): TryOnProduct => ({
  id,
  slug: `abaya-${id}`,
  name: `Abaya ${id}`,
  status: 'active',
  vendor: { id: 9, name: 'Noor Atelier', slug: 'noor-atelier' },
  primary_image_url: `https://api-v3.3bayti.ae/uploads/products/${id}.jpg`,
  try_on_enabled: false,
  eligibility: { eligible: true, reason: null },
  ...patch,
});

const STATUS: TryOnStatus = { available: true, tryon_flag: true, ai_enabled: true, enabled_products: 2, eligible_formats: ['jpeg', 'png', 'webp'] };

class FakeTryOnService {
  readonly status = signal<TryOnStatus | null>(null);
  statusResponse: Observable<TryOnStatus> = of(STATUS);
  readonly loadStatus = jasmine.createSpy('loadStatus').and.callFake(() => {
    return new Observable<TryOnStatus>((sub) =>
      this.statusResponse.subscribe({
        next: (s) => { this.status.set(s); sub.next(s); },
        error: (e) => sub.error(e),
        complete: () => sub.complete(),
      }),
    );
  });
  readonly listProducts = jasmine.createSpy('listProducts').and.returnValue(
    of<TryOnProductPage>({ items: [product(1), product(2, { try_on_enabled: true })], total: 2, limit: 20, offset: 0, hasMore: false }),
  );
  readonly setEnabled = jasmine.createSpy('setEnabled');
  readonly bulkSetEnabled = jasmine.createSpy('bulkSetEnabled');
  readonly bumpEnabledCount = jasmine.createSpy('bumpEnabledCount');
}

const t = (key: string, params?: Record<string, unknown>) => (params ? `${key}${JSON.stringify(params)}` : key);

function setup(opts: { canEdit?: boolean } = {}) {
  const canEdit = opts.canEdit ?? true;
  const svc = new FakeTryOnService();
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  TestBed.configureTestingModule({
    providers: [
      { provide: TryOnAdminService, useValue: svc },
      { provide: PermissionService, useValue: { can: (p: string) => canEdit || p !== 'products.edit' } },
      { provide: HotToastService, useValue: toast },
      { provide: I18nService, useValue: { t } },
      { provide: NavigationHistoryService, useValue: jasmine.createSpyObj('NavigationHistoryService', ['back']) },
    ],
  });
  const component = TestBed.runInInjectionContext(() => new AdminTryOnComponent());
  return { component, svc, toast };
}

function query(patch: Partial<AxQueryState> = {}): AxQueryState {
  return { ...AX_EMPTY_QUERY, ...patch };
}

function fetchRows(component: AdminTryOnComponent, q = query()): TryOnRow[] {
  let rows: readonly TryOnRow[] = [];
  (component as any).fetch(q).subscribe((r: { rows: readonly TryOnRow[] }) => (rows = r.rows));
  return [...rows];
}

function checkboxEvent(checked: boolean): Event {
  const input = document.createElement('input');
  input.type = 'checkbox';
  input.checked = checked;
  return { target: input } as unknown as Event;
}

describe('AdminTryOnComponent (logic)', () => {
  describe('status + table setup', () => {
    it('loads the status, then builds the table on the Enabled tab when products are enabled', () => {
      const { component, svc } = setup();
      component.ngOnInit();

      expect(svc.loadStatus).toHaveBeenCalledTimes(1);
      expect(component.statusLoading()).toBeFalse();
      const cfg = component.config!;
      expect(cfg).toBeTruthy();
      expect(cfg.filters?.[0].key).toBe('enabled');
      expect(cfg.filters?.[0].type).toBe('chips');
      expect(cfg.filters?.[0].defaultValue).toBe('true');
      expect(cfg.columns.map((c) => c.key)).toEqual(['image', 'name', 'store', 'eligibility', 'try_on_enabled']);
      expect(cfg.selectable).toBeTrue();
      expect(cfg.pageSize).toBe(20);
      expect(cfg.bulkActions?.map((a) => a.id)).toEqual(['enable', 'disable']);
      expect(cfg.bulkActions?.every((a) => a.can?.([]) === true)).toBeTrue();
    });

    it('defaults to All when nothing is enabled', () => {
      const { component, svc } = setup();
      svc.statusResponse = of({ ...STATUS, enabled_products: 0 });
      component.ngOnInit();
      expect(component.config?.filters?.[0].defaultValue).toBe('');
    });

    it('explains which flags are off while Try-On is unavailable, and still builds the list', () => {
      const { component, svc } = setup();
      svc.statusResponse = of({ ...STATUS, available: false, tryon_flag: false });
      component.ngOnInit();
      expect(component.offReasons()).toEqual(['tryon.off.flag_tryon']);
      expect(component.config).toBeTruthy();
    });

    it('shows an error (and the list on All) when the status fails', () => {
      const { component, svc } = setup();
      svc.statusResponse = throwError(() => ({ status: 500, error: { error: { code: 'X', message: 'Status down' } } }));
      component.ngOnInit();
      expect(component.statusError()).toBe('Status down');
      expect(component.config?.filters?.[0].defaultValue).toBe('');
      expect(component.offReasons()).toEqual([]);
    });

    it('is read-only without products.edit: no row selection, no bulk actions', () => {
      const { component } = setup({ canEdit: false });
      component.ngOnInit();
      expect(component.config?.selectable).toBeFalse();
      expect(component.config?.bulkActions?.every((a) => a.can?.([]) === false)).toBeTrue();
    });
  });

  describe('fetching rows (T2)', () => {
    it('maps the table query to page/limit/q/enabled and the items to rows', () => {
      const { component, svc } = setup();
      const rows = fetchRows(component, query({ pageIndex: 2, pageSize: 50, search: 'kaftan', filters: { enabled: 'true' } }));

      expect(svc.listProducts).toHaveBeenCalledOnceWith({ q: 'kaftan', enabled: true, page: 3, limit: 50 });
      expect(rows.map((r) => r.id)).toEqual([1, 2]);
      expect(rows[0].store).toBe('Noor Atelier');

      fetchRows(component, query({ filters: { enabled: '' } }));
      expect(svc.listProducts.calls.mostRecent().args[0]).toEqual({ q: '', enabled: null, page: 1, limit: 20 });
    });

    it('toasts and rethrows a list error so the table shows its retry state', () => {
      const { component, svc, toast } = setup();
      svc.listProducts.and.returnValue(throwError(() => ({ status: 500 })));
      let failed = false;
      (component as any).fetch(query()).subscribe({ error: () => (failed = true) });
      expect(failed).toBeTrue();
      expect(toast.error).toHaveBeenCalledWith('tryon.list_error');
    });

    it('labels rows by name, falling back to the slug', () => {
      expect(toTryOnRow(product(4, { name: '' })).name).toBe('abaya-4');
      expect(toTryOnRow(product(4, { vendor: null })).store).toBe('');
    });
  });

  describe('per-row switch (T3)', () => {
    it('flips optimistically, then applies the server item and bumps the count', () => {
      const { component, svc, toast } = setup();
      const [row] = fetchRows(component);
      const reply = new Subject<TryOnProduct>();
      svc.setEnabled.and.returnValue(reply);

      component.toggle(row, checkboxEvent(true));

      expect(svc.setEnabled).toHaveBeenCalledOnceWith(1, true);
      expect(component.enabledOf(row)).toBeTrue();
      expect(component.busyOf(row)).toBeTrue();
      expect(component.toggleOf(row).disabled).toBeTrue();

      reply.next(product(1, { try_on_enabled: true }));
      reply.complete();

      expect(component.enabledOf(row)).toBeTrue();
      expect(component.busyOf(row)).toBeFalse();
      expect(svc.bumpEnabledCount).toHaveBeenCalledWith(1);
      expect(toast.success).toHaveBeenCalledWith('tryon.toggle.enabled{"name":"Abaya 1"}');
    });

    it('rolls back on a 422, shows the localized reason and locks the switch with it', () => {
      const { component, svc, toast } = setup();
      const [row] = fetchRows(component);
      svc.setEnabled.and.returnValue(throwError(() => ({
        status: 422,
        error: { error: { code: 'TRYON_IMAGE_INELIGIBLE', message: 'Product image is a GIF.', details: { reason: 'unsupported_format' } } },
      })));

      component.toggle(row, checkboxEvent(true));

      expect(component.enabledOf(row)).toBeFalse();
      expect(component.busyOf(row)).toBeFalse();
      expect(component.eligibilityOf(row)).toEqual({ eligible: false, reason: 'unsupported_format' });
      expect(component.toggleOf(row)).toEqual({ disabled: true, hintKey: 'tryon.reason.unsupported_format' });
      // the localized reason, not the API's English-only message
      expect(toast.error).toHaveBeenCalledWith('tryon.reason.unsupported_format');
      expect(svc.bumpEnabledCount).not.toHaveBeenCalled();
    });

    it('does not call the API to enable an ineligible product, and resets the checkbox', () => {
      const { component, svc } = setup();
      const row = toTryOnRow(product(7, { eligibility: { eligible: false, reason: 'no_image' } }));
      const ev = checkboxEvent(true);

      component.toggle(row, ev);

      expect(svc.setEnabled).not.toHaveBeenCalled();
      expect((ev.target as HTMLInputElement).checked).toBeFalse();
    });

    it('still allows disabling an enabled product whose image became ineligible', () => {
      const { component, svc } = setup();
      const row = toTryOnRow(product(8, { try_on_enabled: true, eligibility: { eligible: false, reason: 'unsupported_format' } }));
      svc.setEnabled.and.returnValue(of(product(8, { try_on_enabled: false, eligibility: { eligible: false, reason: 'unsupported_format' } })));

      component.toggle(row);

      expect(svc.setEnabled).toHaveBeenCalledOnceWith(8, false);
      expect(component.enabledOf(row)).toBeFalse();
      expect(svc.bumpEnabledCount).toHaveBeenCalledWith(-1);
    });

    it('is a no-op without products.edit', () => {
      const { component, svc } = setup({ canEdit: false });
      const [row] = fetchRows(component);
      component.toggle(row);
      expect(svc.setEnabled).not.toHaveBeenCalled();
      expect(component.toggleOf(row).hintKey).toBe('tryon.toggle.read_only');
    });

    it('drops settled row patches when fresh rows arrive', () => {
      const { component, svc } = setup();
      const [row] = fetchRows(component);
      svc.setEnabled.and.returnValue(of(product(1, { try_on_enabled: true })));
      component.toggle(row);
      expect(component.enabledOf(row)).toBeTrue();

      const [fresh] = fetchRows(component); // server still says false for this stale fixture
      expect(component.enabledOf(fresh)).toBeFalse();
    });

    it('keeps a patch that settled after the list request was sent (stale response)', () => {
      const { component, svc } = setup();
      const [row] = fetchRows(component);

      // A refresh goes out (e.g. after a bulk run) and is still in flight…
      const listReply = new Subject<TryOnProductPage>();
      svc.listProducts.and.returnValue(listReply);
      let rows: readonly TryOnRow[] = [];
      (component as any).fetch(query()).subscribe((r: { rows: readonly TryOnRow[] }) => (rows = r.rows));

      // …when a single switch is flipped and its PUT finishes first.
      svc.setEnabled.and.returnValue(of(product(1, { try_on_enabled: true })));
      component.toggle(row);
      expect(component.enabledOf(row)).toBeTrue();

      // The older list answer (pre-PUT data) must not flip the switch back.
      listReply.next({ items: [product(1), product(2)], total: 2, limit: 20, offset: 0, hasMore: false });
      listReply.complete();
      expect(rows.length).toBe(2);
      expect(component.enabledOf(rows[0])).toBeTrue();

      // The next request, sent after the PUT settled, supersedes the patch.
      svc.listProducts.and.returnValue(of<TryOnProductPage>({ items: [product(1)], total: 1, limit: 20, offset: 0, hasMore: false }));
      const [fresh] = fetchRows(component);
      expect(component.enabledOf(fresh)).toBeFalse();
    });

    it('keeps an in-flight (busy) patch when rows arrive', () => {
      const { component, svc } = setup();
      const [row] = fetchRows(component);
      svc.setEnabled.and.returnValue(new Subject<TryOnProduct>());
      component.toggle(row);

      const [fresh] = fetchRows(component);
      expect(component.busyOf(fresh)).toBeTrue();
      expect(component.enabledOf(fresh)).toBeTrue();
    });

    it('never prunes patches for an export fetch (the rows on screen stay)', () => {
      const { component, svc } = setup();
      const [row] = fetchRows(component);
      svc.setEnabled.and.returnValue(of(product(1, { try_on_enabled: true })));
      component.toggle(row);

      (component as any).fetch(query(), { forExport: true }).subscribe();
      expect(component.enabledOf(row)).toBeTrue();
    });
  });

  describe('bulk (T4)', () => {
    function withTable(component: AdminTryOnComponent) {
      const table = jasmine.createSpyObj('AxDataTableComponent', ['clearSelection']);
      (component as any).table = table;
      spyOn(component.dataSource, 'retry');
      return table;
    }

    it('enables the selection, toasts the summary, clears the selection and refreshes', () => {
      const { component, svc, toast } = setup();
      const table = withTable(component);
      const result: TryOnBulkResult = { updated: [1, 2], skipped: [{ id: 3, reason: 'unsupported_format' }] };
      svc.bulkSetEnabled.and.returnValue(of(result));
      const rows = [1, 2, 3].map((id) => toTryOnRow(product(id)));

      component.onBulkAction({ action: { id: 'enable' }, selection: rows });

      expect(svc.bulkSetEnabled).toHaveBeenCalledOnceWith([1, 2, 3], true);
      expect(toast.success).toHaveBeenCalledTimes(1);
      expect(toast.success.calls.mostRecent().args[0]).toContain('tryon.bulk.summary_skipped');
      expect(table.clearSelection).toHaveBeenCalled();
      expect(component.dataSource.retry).toHaveBeenCalled();
      expect(svc.loadStatus).toHaveBeenCalled();
      expect(component.bulkBusy()).toBeFalse();
    });

    it('reports an all-skipped run as an error', () => {
      const { component, svc, toast } = setup();
      withTable(component);
      svc.bulkSetEnabled.and.returnValue(of({ updated: [], skipped: [{ id: 3, reason: 'no_image' }] }));

      component.onBulkAction({ action: { id: 'enable' }, selection: [toTryOnRow(product(3))] });

      expect(toast.error).toHaveBeenCalledTimes(1);
      expect(toast.success).not.toHaveBeenCalled();
    });

    it('disables the selection and refreshes even when a chunk fails', () => {
      const { component, svc, toast } = setup();
      withTable(component);
      svc.bulkSetEnabled.and.returnValue(throwError(() => ({ status: 500 })));

      component.onBulkAction({ action: { id: 'disable' }, selection: [toTryOnRow(product(5))] });

      expect(svc.bulkSetEnabled).toHaveBeenCalledOnceWith([5], false);
      expect(toast.error).toHaveBeenCalledWith('tryon.bulk_error');
      expect(component.dataSource.retry).toHaveBeenCalled();
      expect(component.bulkBusy()).toBeFalse();
    });

    it('ignores bulk actions without products.edit', () => {
      const { component, svc } = setup({ canEdit: false });
      withTable(component);
      component.onBulkAction({ action: { id: 'enable' }, selection: [toTryOnRow(product(1))] });
      expect(svc.bulkSetEnabled).not.toHaveBeenCalled();
    });
  });
});
