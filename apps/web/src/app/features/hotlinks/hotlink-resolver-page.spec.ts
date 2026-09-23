import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router, ActivatedRoute } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HotlinkResolverPageComponent } from './hotlink-resolver-page';
import { HotlinkService } from '../../core/hotlinks/hotlink.service';
import { provideI18n } from '../../core/i18n';
import type { HotlinkTarget } from '../../core/hotlinks/hotlink.service';

class StubHotlinks {
  result: HotlinkTarget | null = null;
  resolveCalls: string[] = [];
  async resolve(code: string): Promise<HotlinkTarget | null> {
    this.resolveCalls.push(code);
    return this.result;
  }
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function setup(code: string, result: HotlinkTarget | null): {
  fixture: ComponentFixture<HotlinkResolverPageComponent>;
  hotlinks: StubHotlinks;
  navigate: ReturnType<typeof vi.fn>;
} {
  const hotlinks = new StubHotlinks();
  hotlinks.result = result;
  const navigate = vi.fn().mockResolvedValue(true);

  TestBed.configureTestingModule({
    imports: [HotlinkResolverPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: HotlinkService, useValue: hotlinks },
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => code } } } },
    ],
  });
  const fixture = TestBed.createComponent(HotlinkResolverPageComponent);
  const router = TestBed.inject(Router);
  (router as unknown as { navigateByUrl: unknown }).navigateByUrl = navigate;
  fixture.detectChanges();
  return { fixture, hotlinks, navigate };
}

describe('HotlinkResolverPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('resolves a store code and forwards to /stores/:slug', async () => {
    const { hotlinks, navigate } = setup('abc123', { target_type: 'store', target_slug: 'atelier-noor' });
    await flush();
    expect(hotlinks.resolveCalls).toEqual(['abc123']);
    expect(navigate).toHaveBeenCalledWith('/stores/atelier-noor', { replaceUrl: true });
  });

  it('resolves a style code and forwards to /styles/:slug', async () => {
    const { navigate } = setup('def456', { target_type: 'style', target_slug: 'eid-look-a1b2' });
    await flush();
    expect(navigate).toHaveBeenCalledWith('/styles/eid-look-a1b2', { replaceUrl: true });
  });

  it('shows a not-found message when the code does not resolve', async () => {
    const { fixture, navigate } = setup('bad', null);
    await flush();
    fixture.detectChanges();
    expect(navigate).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('[data-testid="hotlink-failed"]')).not.toBeNull();
  });
});
