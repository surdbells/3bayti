import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { upstreamPostJson, upstreamGet, extractClientIp, type UpstreamContext } from './upstream';
import { createAuthProxyConfig, type AuthProxyConfig } from './config';

const config: AuthProxyConfig = createAuthProxyConfig({
  upstreamBaseUrl: 'https://api-v3.test.local',
  upstreamTimeoutMs: 5_000,
});

const ctx: UpstreamContext = {
  clientIp: '203.0.113.7',
  userAgent: 'TestAgent/1.0',
  acceptLanguage: 'en-US,en;q=0.9',
};

const emptyCtx: UpstreamContext = {
  clientIp: null,
  userAgent: null,
  acceptLanguage: null,
};

/* Capture fetch arguments for assertion. */
function mockFetch(responseInit: {
  status?: number;
  body?: string | object;
  delay?: number;
}): { restore: () => void; calls: Parameters<typeof fetch>[] } {
  const calls: Parameters<typeof fetch>[] = [];
  const originalFetch = globalThis.fetch;

  globalThis.fetch = vi.fn(async (...args: Parameters<typeof fetch>) => {
    calls.push(args);

    /* Honour the AbortSignal from the second argument so the mock
       behaves like a real network call: a real fetch() aborted by
       AbortController rejects with an AbortError; we mirror that. */
    const signal = (args[1] as RequestInit | undefined)?.signal as AbortSignal | undefined;

    if (responseInit.delay !== undefined && responseInit.delay > 0) {
      await new Promise<void>((resolve, reject) => {
        const timer = setTimeout(resolve, responseInit.delay);
        if (signal !== undefined) {
          signal.addEventListener(
            'abort',
            () => {
              clearTimeout(timer);
              reject(new DOMException('The operation was aborted', 'AbortError'));
            },
            { once: true },
          );
        }
      });
    }

    /* Already-aborted (zero-delay case where the caller aborted before
       the body resolves) — still reject. */
    if (signal?.aborted === true) {
      throw new DOMException('The operation was aborted', 'AbortError');
    }

    const status = responseInit.status ?? 200;
    /* The Web Response constructor forbids a non-empty body for 1xx,
       204, 205, and 304. Match real fetch by passing null body in
       those cases. */
    const bodyForbidden = status === 204 || status === 205 || status === 304 || (status >= 100 && status < 200);
    const bodyString = bodyForbidden
      ? null
      : typeof responseInit.body === 'string'
        ? responseInit.body
        : responseInit.body !== undefined
          ? JSON.stringify(responseInit.body)
          : '';
    return new Response(bodyString, {
      status,
      headers: {
        'Content-Type': typeof responseInit.body === 'object' ? 'application/json' : 'text/plain',
      },
    });
  }) as unknown as typeof fetch;

  return {
    restore: () => {
      globalThis.fetch = originalFetch;
    },
    calls,
  };
}

describe('upstreamPostJson', () => {
  it('calls fetch at base + path with POST and JSON body', async () => {
    const harness = mockFetch({ status: 200, body: { ok: true } });
    try {
      await upstreamPostJson('/v3/auth/login', { email: 'a@b.com', password: 'x' }, ctx, config);

      expect(harness.calls).toHaveLength(1);
      const [url, init] = harness.calls[0];
      expect(url).toBe('https://api-v3.test.local/v3/auth/login');
      expect(init?.method).toBe('POST');
      expect(init?.body).toBe(JSON.stringify({ email: 'a@b.com', password: 'x' }));
    } finally {
      harness.restore();
    }
  });

  it('forwards client-context headers (X-Forwarded-For, User-Agent, Accept-Language)', async () => {
    const harness = mockFetch({ status: 200 });
    try {
      await upstreamPostJson('/v3/auth/login', {}, ctx, config);

      const [, init] = harness.calls[0];
      const headers = init?.headers as Record<string, string>;
      expect(headers['X-Forwarded-For']).toBe('203.0.113.7');
      expect(headers['User-Agent']).toBe('TestAgent/1.0');
      expect(headers['Accept-Language']).toBe('en-US,en;q=0.9');
      expect(headers['Content-Type']).toBe('application/json');
    } finally {
      harness.restore();
    }
  });

  it('omits client-context headers when context fields are null', async () => {
    const harness = mockFetch({ status: 200 });
    try {
      await upstreamPostJson('/v3/auth/login', {}, emptyCtx, config);

      const [, init] = harness.calls[0];
      const headers = init?.headers as Record<string, string>;
      expect(headers['X-Forwarded-For']).toBeUndefined();
      expect(headers['User-Agent']).toBeUndefined();
      expect(headers['Accept-Language']).toBeUndefined();
    } finally {
      harness.restore();
    }
  });

  it('parses JSON body into UpstreamResponse.body and exposes raw text', async () => {
    const harness = mockFetch({ status: 200, body: { user: { id: 5 }, access_token: 't' } });
    try {
      const resp = await upstreamPostJson<{ user: { id: number } }>('/v3/auth/login', {}, ctx, config);

      expect(resp.status).toBe(200);
      expect(resp.body).toEqual({ user: { id: 5 }, access_token: 't' });
      expect(resp.raw).toContain('"user"');
    } finally {
      harness.restore();
    }
  });

  it('returns null body and raw text for non-JSON upstream responses', async () => {
    const harness = mockFetch({ status: 502, body: '<html>upstream error</html>' });
    try {
      const resp = await upstreamPostJson('/v3/auth/login', {}, ctx, config);

      expect(resp.status).toBe(502);
      expect(resp.body).toBeNull();
      expect(resp.raw).toBe('<html>upstream error</html>');
    } finally {
      harness.restore();
    }
  });

  it('handles a 204 empty body without throwing', async () => {
    const harness = mockFetch({ status: 204 });
    try {
      const resp = await upstreamPostJson('/v3/auth/logout', {}, ctx, config);

      expect(resp.status).toBe(204);
      expect(resp.body).toBeNull();
      expect(resp.raw).toBe('');
    } finally {
      harness.restore();
    }
  });

  it('forwards non-2xx status codes unchanged (does not throw)', async () => {
    const harness = mockFetch({
      status: 401,
      body: { error_code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong password.' },
    });
    try {
      const resp = await upstreamPostJson('/v3/auth/login', {}, ctx, config);

      expect(resp.status).toBe(401);
      expect(resp.body).toMatchObject({ error_code: 'AUTH_INVALID_CREDENTIALS' });
    } finally {
      harness.restore();
    }
  });

  it('aborts on timeout', async () => {
    /* fetch will hang for 200ms; config has 50ms timeout. */
    const fastConfig = createAuthProxyConfig({
      upstreamBaseUrl: 'https://api-v3.test.local',
      upstreamTimeoutMs: 50,
    });
    const harness = mockFetch({ status: 200, delay: 500 });
    try {
      /* The aborted fetch should throw — caller decides what to do. */
      await expect(upstreamPostJson('/v3/auth/login', {}, ctx, fastConfig)).rejects.toThrow();
    } finally {
      harness.restore();
    }
  });

  it('joins base URL and path correctly when base has trailing slash', async () => {
    const harness = mockFetch({ status: 200 });
    const trailing = createAuthProxyConfig({ upstreamBaseUrl: 'https://api-v3.test.local///' });
    try {
      await upstreamPostJson('/v3/auth/login', {}, ctx, trailing);
      expect(harness.calls[0][0]).toBe('https://api-v3.test.local/v3/auth/login');
    } finally {
      harness.restore();
    }
  });

  it('joins correctly when path lacks a leading slash', async () => {
    const harness = mockFetch({ status: 200 });
    try {
      await upstreamPostJson('v3/auth/login', {}, ctx, config);
      expect(harness.calls[0][0]).toBe('https://api-v3.test.local/v3/auth/login');
    } finally {
      harness.restore();
    }
  });
});

describe('upstreamGet', () => {
  it('calls fetch with GET and no body', async () => {
    const harness = mockFetch({ status: 200, body: { user: { id: 1 } } });
    try {
      await upstreamGet('/v3/auth/me', null, ctx, config);

      const [, init] = harness.calls[0];
      expect(init?.method).toBe('GET');
      expect(init?.body).toBeUndefined();
    } finally {
      harness.restore();
    }
  });

  it('attaches the Authorization header when provided', async () => {
    const harness = mockFetch({ status: 200 });
    try {
      await upstreamGet('/v3/auth/me', 'Bearer xyz', ctx, config);

      const [, init] = harness.calls[0];
      const headers = init?.headers as Record<string, string>;
      expect(headers['Authorization']).toBe('Bearer xyz');
    } finally {
      harness.restore();
    }
  });

  it('omits the Authorization header when null', async () => {
    const harness = mockFetch({ status: 200 });
    try {
      await upstreamGet('/v3/auth/me', null, ctx, config);

      const [, init] = harness.calls[0];
      const headers = init?.headers as Record<string, string>;
      expect(headers['Authorization']).toBeUndefined();
    } finally {
      harness.restore();
    }
  });
});

describe('extractClientIp', () => {
  it('returns the first entry from X-Forwarded-For when comma-separated', () => {
    expect(extractClientIp({ 'x-forwarded-for': '203.0.113.7, 198.51.100.1, 10.0.0.1' })).toBe('203.0.113.7');
  });

  it('returns the single X-Forwarded-For entry when not comma-separated', () => {
    expect(extractClientIp({ 'x-forwarded-for': '203.0.113.7' })).toBe('203.0.113.7');
  });

  it('falls back to CF-Connecting-IP when X-Forwarded-For is absent', () => {
    expect(extractClientIp({ 'cf-connecting-ip': '198.51.100.5' })).toBe('198.51.100.5');
  });

  it('prefers X-Forwarded-For over CF-Connecting-IP', () => {
    expect(
      extractClientIp({
        'x-forwarded-for': '203.0.113.7',
        'cf-connecting-ip': '198.51.100.5',
      }),
    ).toBe('203.0.113.7');
  });

  it('returns null when neither header is present', () => {
    expect(extractClientIp({})).toBeNull();
  });

  it('handles array-valued headers (Express normalises some headers to arrays)', () => {
    expect(extractClientIp({ 'x-forwarded-for': ['203.0.113.7', '198.51.100.1'] })).toBe('203.0.113.7');
  });
});
