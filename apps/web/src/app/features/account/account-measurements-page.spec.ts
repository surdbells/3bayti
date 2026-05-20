import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient, HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountMeasurementsPageComponent } from './account-measurements-page';
import { MeasurementService, Measurement, MeasurementUpsert } from './measurement.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';

function makeMeasurement(o: Partial<Measurement> = {}): Measurement {
  return {
    id: 1, category_id: null,
    values: { bust_chest: 90, waist: 74, hips: 98, shoulder: 40, sleeve_length: 60, height: 168 },
    notes: 'relaxed', updated_at: '2026-05-01T10:00:00Z', ...o,
  };
}

class StubMeasurementService {
  private _loading = signal(false);
  private _saving = signal(false);
  isLoading = this._loading.asReadonly();
  isSaving = this._saving.asReadonly();

  getResult: Measurement | null = null;
  getThrows = false;
  upsertCalls: MeasurementUpsert[] = [];
  upsertThrows: unknown = null;
  upsertResult: Measurement = makeMeasurement();
  clearCalled = false;
  clearThrows: unknown = null;

  async getDefault(): Promise<Measurement | null> {
    if (this.getThrows) throw new Error('load failed');
    return this.getResult;
  }
  async upsertDefault(input: MeasurementUpsert): Promise<Measurement> {
    this.upsertCalls.push(input);
    if (this.upsertThrows !== null) throw this.upsertThrows;
    return this.upsertResult;
  }
  async clearDefault(): Promise<void> {
    this.clearCalled = true;
    if (this.clearThrows !== null) throw this.clearThrows;
  }
}

class StubToast {
  calls: Array<{ kind: string; msg: string }> = [];
  success(m: string): string { this.calls.push({ kind: 'success', msg: m }); return ''; }
  error(m: string): string { this.calls.push({ kind: 'error', msg: m }); return ''; }
  info(m: string): string { this.calls.push({ kind: 'info', msg: m }); return ''; }
  warning(m: string): string { this.calls.push({ kind: 'warning', msg: m }); return ''; }
}

function setup(opts: {
  measurement?: Measurement | null;
  getThrows?: boolean;
  upsertThrows?: unknown;
  upsertResult?: Measurement;
  clearThrows?: unknown;
} = {}): {
  fixture: ComponentFixture<AccountMeasurementsPageComponent>;
  measurements: StubMeasurementService;
  toast: StubToast;
} {
  const measurements = new StubMeasurementService();
  measurements.getResult = opts.measurement ?? null;
  if (opts.getThrows === true) measurements.getThrows = true;
  if (opts.upsertThrows !== undefined) measurements.upsertThrows = opts.upsertThrows;
  if (opts.upsertResult !== undefined) measurements.upsertResult = opts.upsertResult;
  if (opts.clearThrows !== undefined) measurements.clearThrows = opts.clearThrows;
  const toast = new StubToast();

  TestBed.configureTestingModule({
    imports: [AccountMeasurementsPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: MeasurementService, useValue: measurements },
      { provide: ToastService, useValue: toast },
    ],
  });
  const fixture = TestBed.createComponent(AccountMeasurementsPageComponent);
  fixture.detectChanges();
  return { fixture, measurements, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function q(fixture: ComponentFixture<AccountMeasurementsPageComponent>, testid: string): HTMLElement | null {
  return fixture.nativeElement.querySelector(`[data-testid="${testid}"]`);
}

function setField(fixture: ComponentFixture<AccountMeasurementsPageComponent>, testid: string, value: string): void {
  const el = q(fixture, testid) as HTMLInputElement | HTMLTextAreaElement;
  el.value = value;
  el.dispatchEvent(new Event('input'));
}

describe('AccountMeasurementsPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('load', () => {
    it('starts with an empty form when nothing is saved', async () => {
      const { fixture } = setup({ measurement: null });
      await flush();
      fixture.detectChanges();
      expect((q(fixture, 'meas-waist') as HTMLInputElement).value).toBe('');
      /* No saved set → no Clear button. */
      expect(q(fixture, 'meas-clear')).toBeNull();
    });

    it('populates the form + shows Clear when a set exists', async () => {
      const { fixture } = setup({ measurement: makeMeasurement({ values: { waist: 74, height: 168 } }) });
      await flush();
      fixture.detectChanges();
      expect((q(fixture, 'meas-waist') as HTMLInputElement).value).toBe('74');
      expect((q(fixture, 'meas-height') as HTMLInputElement).value).toBe('168');
      expect(q(fixture, 'meas-clear')).not.toBeNull();
    });

    it('toasts on load failure', async () => {
      const { toast } = setup({ getThrows: true });
      await flush();
      expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
    });
  });

  describe('save', () => {
    it('sends only the filled fields + notes', async () => {
      const { fixture, measurements } = setup({ measurement: null });
      await flush();
      fixture.detectChanges();
      setField(fixture, 'meas-waist', '76');
      setField(fixture, 'meas-hips', '99');
      setField(fixture, 'meas-notes', '  fitted  ');
      fixture.detectChanges();
      (q(fixture, 'meas-save') as HTMLButtonElement).click();
      await flush();
      expect(measurements.upsertCalls).toHaveLength(1);
      expect(measurements.upsertCalls[0]).toEqual({ values: { waist: 76, hips: 99 }, notes: 'fitted' });
    });

    it('sends notes:null when notes left blank', async () => {
      const { fixture, measurements } = setup({ measurement: null });
      await flush();
      fixture.detectChanges();
      setField(fixture, 'meas-height', '170');
      fixture.detectChanges();
      (q(fixture, 'meas-save') as HTMLButtonElement).click();
      await flush();
      expect(measurements.upsertCalls[0]).toEqual({ values: { height: 170 }, notes: null });
    });

    it('toasts success and reveals Clear after first save', async () => {
      const { fixture, toast } = setup({
        measurement: null,
        upsertResult: makeMeasurement({ values: { waist: 76 }, notes: null }),
      });
      await flush();
      fixture.detectChanges();
      setField(fixture, 'meas-waist', '76');
      fixture.detectChanges();
      (q(fixture, 'meas-save') as HTMLButtonElement).click();
      await flush();
      fixture.detectChanges();
      expect(toast.calls.some(c => c.kind === 'success')).toBe(true);
      expect(q(fixture, 'meas-clear')).not.toBeNull();
    });

    it('surfaces a network error as a toast', async () => {
      const netErr = new HttpErrorResponse({ status: 0, statusText: 'Unknown Error' });
      const { fixture, toast } = setup({ measurement: null, upsertThrows: netErr });
      await flush();
      fixture.detectChanges();
      setField(fixture, 'meas-waist', '76');
      fixture.detectChanges();
      (q(fixture, 'meas-save') as HTMLButtonElement).click();
      await flush();
      expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
    });
  });

  describe('clear', () => {
    it('clears via the confirm modal', async () => {
      const { fixture, measurements, toast } = setup({ measurement: makeMeasurement() });
      await flush();
      fixture.detectChanges();
      /* Open the confirm modal. */
      (q(fixture, 'meas-clear') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(q(fixture, 'confirm-modal')).not.toBeNull();
      /* Confirm. */
      (q(fixture, 'confirm-modal-confirm') as HTMLButtonElement).click();
      await flush();
      fixture.detectChanges();
      expect(measurements.clearCalled).toBe(true);
      expect(toast.calls.some(c => c.kind === 'success')).toBe(true);
      /* Clear button gone after clearing. */
      expect(q(fixture, 'meas-clear')).toBeNull();
    });

    it('does not clear when the modal is dismissed', async () => {
      const { fixture, measurements } = setup({ measurement: makeMeasurement() });
      await flush();
      fixture.detectChanges();
      (q(fixture, 'meas-clear') as HTMLButtonElement).click();
      fixture.detectChanges();
      (q(fixture, 'confirm-modal-cancel') as HTMLButtonElement).click();
      await flush();
      fixture.detectChanges();
      expect(measurements.clearCalled).toBe(false);
      expect(q(fixture, 'confirm-modal')).toBeNull();
    });
  });
});
