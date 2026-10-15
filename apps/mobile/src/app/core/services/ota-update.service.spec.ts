import { TestBed } from '@angular/core/testing';

import { CheckoutActivityService } from './checkout-activity.service';
import {
  OTA_IS_NATIVE,
  OTA_STORE,
  OTA_UPDATER,
  OtaUpdateService,
  type OtaStore,
  type OtaUpdater,
} from './ota-update.service';

/**
 * OtaUpdateService over a fake @capgo/capacitor-updater that follows the real
 * 8.51.3 contract: release notes arrive as `comment`, an up-to-date check
 * resolves with kind "up_to_date" (version = the current bundle, no url), and
 * failures are reported through events. Covers the automatic (autoUpdate
 * event) path, the on-demand checkNow() path, the skip list for versions that
 * failed on this device, the checkout hold on background installs, and the
 * restart path.
 */
class FakeUpdater {
  readonly listeners = new Map<string, (event: any) => void>();
  latest: Record<string, unknown> = { kind: 'up_to_date', error: 'no_new_version_available', message: 'No update', version: '1.7.0' };
  failedUpdate: { bundle: { version: string } } | null = null;
  reloadError: Error | null = null;
  /** Runs inside getLatest(), before it resolves (to simulate concurrent events). */
  duringGetLatest: (() => void) | null = null;
  getLatestCalls = 0;
  reloadCalls = 0;
  downloadCalls: string[] = [];
  nextCalls: Array<{ id: string }> = [];
  delayCalls: string[] = [];

  async addListener(event: string, fn: (event: any) => void): Promise<{ remove: () => Promise<void> }> {
    this.listeners.set(event, fn);
    return { remove: async () => undefined };
  }
  async getLatest(): Promise<Record<string, unknown>> {
    this.getLatestCalls++;
    this.duringGetLatest?.();
    return this.latest;
  }
  async current(): Promise<unknown> {
    return { bundle: { version: '1.7.0' } };
  }
  async download(options: { version: string }): Promise<{ id: string; version: string }> {
    this.downloadCalls.push(options.version);
    this.emit('download', { percent: 50, bundle: { version: options.version } });
    return { id: `id-${options.version}`, version: options.version };
  }
  async next(options: { id: string }): Promise<void> {
    this.nextCalls.push(options);
  }
  async reload(): Promise<void> {
    this.reloadCalls++;
    if (this.reloadError) {
      throw this.reloadError;
    }
  }
  async setMultiDelay(options: { delayConditions: Array<{ kind: string }> }): Promise<void> {
    this.delayCalls.push(`set:${options.delayConditions.map((c) => c.kind).join(',')}`);
  }
  async cancelDelay(): Promise<void> {
    this.delayCalls.push('cancel');
  }
  async getFailedUpdate(): Promise<unknown> {
    const failed = this.failedUpdate;
    this.failedUpdate = null; // the plugin clears it after one read
    return failed;
  }

  emit(event: string, payload: unknown): void {
    this.listeners.get(event)?.(payload);
  }
}

class MemoryStore implements OtaStore {
  readonly data = new Map<string, string>();
  getItem(key: string): string | null {
    return this.data.get(key) ?? null;
  }
  setItem(key: string, value: string): void {
    this.data.set(key, value);
  }
}

/** Let pending promise chains (the notes lookup) settle. */
const flush = () => new Promise<void>((resolve) => setTimeout(resolve, 0));

const UPDATE = (version: string, extra: Record<string, unknown> = {}) => ({
  version,
  url: `https://x/${version}.zip`,
  checksum: 'c',
  ...extra,
});

describe('OtaUpdateService', () => {
  let updater: FakeUpdater;
  let store: MemoryStore;
  let service: OtaUpdateService;
  let checkout: CheckoutActivityService;

  function create(): void {
    TestBed.configureTestingModule({
      providers: [
        { provide: OTA_UPDATER, useValue: updater as unknown as OtaUpdater },
        { provide: OTA_IS_NATIVE, useValue: () => true },
        { provide: OTA_STORE, useValue: store },
      ],
    });
    service = TestBed.inject(OtaUpdateService);
    checkout = TestBed.inject(CheckoutActivityService);
  }

  beforeEach(async () => {
    updater = new FakeUpdater();
    store = new MemoryStore();
    create();
    await service.init();
    updater.delayCalls = []; // ignore init's stale-delay cleanup
  });

  describe('automatic (autoUpdate) path', () => {
    it('takes the version from the download event', () => {
      updater.emit('download', { percent: 30, bundle: { version: '1.7.2' } });

      expect(service.status()).toBe('downloading');
      expect(service.percent()).toBe(30);
      expect(service.version()).toBe('1.7.2');
    });

    it('populates version and notes (sent as `comment`) when updateAvailable fires', async () => {
      updater.latest = UPDATE('1.7.2', { comment: 'Faster checkout', notes: 'Faster checkout' });

      updater.emit('updateAvailable', { bundle: { version: '1.7.2' } });
      await flush();

      expect(service.status()).toBe('ready');
      expect(service.version()).toBe('1.7.2');
      expect(service.summary()).toBe('Faster checkout');
    });

    it('uses the notes iOS stores on the downloaded bundle without a lookup', async () => {
      updater.emit('downloadComplete', { bundle: { version: '1.7.2', comment: 'Bug fixes' } });
      await flush();

      expect(service.summary()).toBe('Bug fixes');
      expect(updater.getLatestCalls).toBe(0);
    });

    it('does a single notes lookup for downloadComplete + updateAvailable', async () => {
      updater.latest = UPDATE('1.7.2', { notes: 'Bug fixes' });

      updater.emit('downloadComplete', { bundle: { version: '1.7.2' } });
      updater.emit('updateAvailable', { bundle: { version: '1.7.2' } });
      await flush();

      expect(service.summary()).toBe('Bug fixes');
      expect(updater.getLatestCalls).toBe(1);
    });

    it('never treats a server `message` as release notes', async () => {
      updater.latest = UPDATE('1.7.2', { message: 'Server status text' });

      updater.emit('updateAvailable', { bundle: { version: '1.7.2' } });
      await flush();

      expect(service.summary()).toBeNull();
    });

    it('ignores notes that describe a different bundle than the one staged', async () => {
      updater.latest = UPDATE('1.7.3', { comment: 'Newer release' });

      updater.emit('updateAvailable', { bundle: { version: '1.7.2' } });
      await flush();

      expect(service.version()).toBe('1.7.2');
      expect(service.summary()).toBeNull();
    });

    it("drops the previous version's notes when a different bundle starts downloading", async () => {
      updater.latest = UPDATE('1.7.2', { comment: 'Old notes' });
      updater.emit('updateAvailable', { bundle: { version: '1.7.2' } });
      await flush();

      updater.emit('download', { percent: 5, bundle: { version: '1.7.3' } });

      expect(service.version()).toBe('1.7.3');
      expect(service.summary()).toBeNull();
    });

    it('a download failure does not hide an already staged bundle', async () => {
      updater.emit('updateAvailable', { bundle: { version: '1.7.2', comment: 'x' } });
      updater.emit('downloadFailed', { version: '1.7.0' });

      expect(service.status()).toBe('ready');
    });
  });

  describe('checkNow()', () => {
    it('downloads, stages via next() and keeps the notes, without reloading', async () => {
      updater.latest = UPDATE('1.7.2', { comment: 'Faster checkout' });

      await service.checkNow();
      // The plugin also fires its completion event for this download.
      updater.emit('downloadComplete', { bundle: { version: '1.7.2' } });
      await flush();

      expect(service.status()).toBe('ready');
      expect(service.version()).toBe('1.7.2');
      expect(service.summary()).toBe('Faster checkout');
      expect(updater.nextCalls).toEqual([{ id: 'id-1.7.2' }]);
      expect(updater.reloadCalls).toBe(0);
      expect(updater.getLatestCalls).toBe(1);
    });

    it('treats the plugin\'s "up to date" answer as no update', async () => {
      // Android getLatest() on kind=up_to_date: version = the current bundle, no url.
      updater.latest = { kind: 'up_to_date', error: 'no_new_version_available', message: 'No update', version: '1.7.0' };

      await service.checkNow();

      expect(service.status()).toBe('idle');
      expect(updater.downloadCalls).toEqual([]);
      expect(updater.nextCalls).toEqual([]);
    });

    it('does nothing while a bundle is already staged', async () => {
      updater.emit('updateAvailable', { bundle: { version: '1.7.2', comment: 'x' } });
      updater.latest = UPDATE('1.7.3');

      await service.checkNow();

      expect(updater.getLatestCalls).toBe(0);
      expect(service.status()).toBe('ready');
    });

    it('does not start a second download when the automatic path took over meanwhile', async () => {
      updater.latest = UPDATE('1.7.2');
      updater.duringGetLatest = () => updater.emit('download', { percent: 10, bundle: { version: '1.7.2' } });

      await service.checkNow();

      expect(updater.downloadCalls).toEqual([]);
      expect(service.status()).toBe('downloading');
    });
  });

  describe('versions that failed on this device', () => {
    it('never re-fetches a version that failed to boot (rolled back before this bundle started)', async () => {
      TestBed.resetTestingModule();
      updater = new FakeUpdater();
      updater.failedUpdate = { bundle: { version: '1.7.2' } };
      create();
      await service.init();
      updater.latest = UPDATE('1.7.2');

      await service.checkNow();

      expect(updater.downloadCalls).toEqual([]);
      expect(service.status()).toBe('idle');
    });

    it('never presents or auto-restarts into a boot-failed version the plugin re-stages', async () => {
      updater.emit('updateFailed', { bundle: { version: '1.7.2' } });
      await new Promise<void>((resolve) => setTimeout(resolve, 0));

      updater.emit('download', { percent: 40, bundle: { version: '1.7.2' } });
      updater.emit('updateAvailable', { bundle: { version: '1.7.2' } });

      expect(service.status()).not.toBe('downloading');
      expect(service.status()).not.toBe('ready');
    });

    it('remembers the failure across app restarts (persisted)', async () => {
      updater.emit('updateFailed', { bundle: { version: '1.7.2' } });

      TestBed.resetTestingModule();
      updater = new FakeUpdater();
      create(); // same store, fresh service (a new JS context)
      await service.init();
      updater.latest = UPDATE('1.7.2');
      await service.checkNow();

      expect(updater.downloadCalls).toEqual([]);
    });

    it('skips a version after repeated download/verification failures, until one succeeds', async () => {
      updater.emit('downloadFailed', { version: '1.7.2' });
      updater.emit('downloadFailed', { version: '1.7.2' });
      updater.latest = UPDATE('1.7.2');

      await service.checkNow();
      expect(updater.downloadCalls).toEqual([]);
      // ...and its automatic re-download no longer throws up the blocking sheet.
      updater.emit('download', { percent: 20, bundle: { version: '1.7.2' } });
      expect(service.status()).not.toBe('downloading');

      // A later successful download clears it and is presented normally.
      updater.emit('downloadComplete', { bundle: { version: '1.7.2', comment: 'ok' } });
      expect(service.status()).toBe('ready');
    });

    it('a single failed download is not enough to skip a version', async () => {
      updater.emit('downloadFailed', { version: '1.7.2' });
      updater.latest = UPDATE('1.7.2');

      await service.checkNow();

      expect(updater.downloadCalls).toEqual(['1.7.2']);
    });
  });

  describe('checkout hold on background installs', () => {
    it('sets a kill delay while a checkout is open and cancels it after', async () => {
      checkout.begin();
      TestBed.tick();
      await flush();
      expect(updater.delayCalls).toEqual(['set:kill']);

      checkout.begin(); // nested window: no extra call
      TestBed.tick();
      checkout.end();
      TestBed.tick();
      await flush();
      expect(updater.delayCalls).toEqual(['set:kill']);

      checkout.end();
      TestBed.tick();
      await flush();
      expect(updater.delayCalls).toEqual(['set:kill', 'cancel']);
    });
  });

  describe('restartToApply()', () => {
    it('reloads into the staged bundle', async () => {
      expect(await service.restartToApply()).toBeTrue();
      expect(updater.reloadCalls).toBe(1);
    });

    it('never reloads during a checkout', async () => {
      checkout.begin();
      expect(service.deferredForCheckout).toBeTrue();

      expect(await service.restartToApply()).toBeFalse();

      expect(updater.reloadCalls).toBe(0);
    });

    it('takes the sheet down when the reload fails, and does not present that version again', async () => {
      updater.emit('updateAvailable', { bundle: { version: '1.7.2', comment: 'x' } });
      updater.reloadError = new Error('Reload failed');

      expect(await service.restartToApply()).toBeFalse();
      expect(service.status()).toBe('failed');

      updater.emit('updateAvailable', { bundle: { version: '1.7.2', comment: 'x' } });
      expect(service.status()).toBe('failed');
    });
  });
});

describe('OtaUpdateService on the web', () => {
  it('is inert outside the native shell', async () => {
    const updater = new FakeUpdater();
    TestBed.configureTestingModule({
      providers: [
        { provide: OTA_UPDATER, useValue: updater as unknown as OtaUpdater },
        { provide: OTA_IS_NATIVE, useValue: () => false },
        { provide: OTA_STORE, useValue: new MemoryStore() },
      ],
    });
    const service = TestBed.inject(OtaUpdateService);
    const checkout = TestBed.inject(CheckoutActivityService);

    await service.init();
    await service.checkNow();
    await service.restartToApply();
    checkout.begin();
    TestBed.tick();
    await flush();

    expect(updater.listeners.size).toBe(0);
    expect(updater.getLatestCalls).toBe(0);
    expect(updater.reloadCalls).toBe(0);
    expect(updater.delayCalls).toEqual([]);
  });
});

describe('OTA_UPDATER default', () => {
  it('is a plain object, not the Capacitor plugin proxy (no phantom ngOnDestroy)', () => {
    TestBed.configureTestingModule({});
    const updater = TestBed.inject(OTA_UPDATER) as unknown as Record<string, unknown>;

    expect(updater['ngOnDestroy']).toBeUndefined();
    expect(updater['then']).toBeUndefined();
    expect(typeof updater['getLatest']).toBe('function');
  });
});
