import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { MeasurementService, Measurement } from './measurement.service';

const V3 = 'https://api-v3.3bayti.ae/v3/me/measurements/default';

function makeMeasurement(o: Partial<Measurement> = {}): Measurement {
  return {
    id: 1,
    category_id: null,
    values: { waist: 78, hips: 96 },
    notes: 'relaxed fit',
    updated_at: '2026-05-01T10:00:00Z',
    ...o,
  };
}

function setup(): { service: MeasurementService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), MeasurementService],
  });
  return {
    service: TestBed.inject(MeasurementService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('MeasurementService', () => {
  afterEach(() => {
    try {
      TestBed.inject(HttpTestingController).verify();
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('getDefault', () => {
    it('returns the measurement when present', async () => {
      const { service, controller } = setup();
      const promise = service.getDefault();
      const req = controller.expectOne(V3);
      expect(req.request.method).toBe('GET');
      req.flush({ measurements: makeMeasurement({ id: 7 }) });
      const result = await promise;
      expect(result?.id).toBe(7);
      expect(result?.values['waist']).toBe(78);
    });

    it('returns null when no set is saved (measurements: null)', async () => {
      const { service, controller } = setup();
      const promise = service.getDefault();
      controller.expectOne(V3).flush({ measurements: null });
      expect(await promise).toBeNull();
    });

    it('toggles isLoading around the request', async () => {
      const { service, controller } = setup();
      const promise = service.getDefault();
      expect(service.isLoading()).toBe(true);
      controller.expectOne(V3).flush({ measurements: null });
      await promise;
      expect(service.isLoading()).toBe(false);
    });
  });

  describe('upsertDefault', () => {
    it('PUTs the values + notes and returns the saved set', async () => {
      const { service, controller } = setup();
      const promise = service.upsertDefault({ values: { waist: 80 }, notes: 'tweaked' });
      const req = controller.expectOne(V3);
      expect(req.request.method).toBe('PUT');
      expect(req.request.body).toEqual({ values: { waist: 80 }, notes: 'tweaked' });
      req.flush({ measurements: makeMeasurement({ values: { waist: 80 }, notes: 'tweaked' }) });
      const result = await promise;
      expect(result.values['waist']).toBe(80);
      expect(result.notes).toBe('tweaked');
    });

    it('toggles isSaving around the request', async () => {
      const { service, controller } = setup();
      const promise = service.upsertDefault({ values: {} });
      expect(service.isSaving()).toBe(true);
      controller.expectOne(V3).flush({ measurements: makeMeasurement() });
      await promise;
      expect(service.isSaving()).toBe(false);
    });
  });

  describe('clearDefault', () => {
    it('DELETEs the default set', async () => {
      const { service, controller } = setup();
      const promise = service.clearDefault();
      const req = controller.expectOne(V3);
      expect(req.request.method).toBe('DELETE');
      req.flush(null, { status: 204, statusText: 'No Content' });
      await promise;
      expect(service.isSaving()).toBe(false);
    });
  });
});
