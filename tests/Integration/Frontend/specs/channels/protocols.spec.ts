import { test, expect } from '@playwright/test';
import { BACKEND, FRONTEND } from '../../support/backend';

declare global {
  interface Window {
    consumeProtocol: typeof import('../../fixtures/channels/protocols')['consumeProtocol'];
  }
}

// A fresh archive alias can require a cold Vite build of both SDKs.
test.setTimeout(60_000);

for (const protocol of ['agui', 'vercel'] as const) {
  test(`PHP ${protocol} events survive Pusher fragmentation and reach the official client`, async ({ page, request }) => {
    const response = await request.post(`${BACKEND}/_test/channels`, {
      data: { transport: 'pusher', outcome: 'completed', protocol },
    });
    expect(response.ok(), await response.text()).toBe(true);
    const { frames } = await response.json();
    expect(frames.some((frame: { type: string }) => frame.type === 'stream.fragment')).toBe(true);
    await page.goto(`${FRONTEND}/channels/protocols.html`);
    const text = await page.evaluate(async ({ frames, protocol }) => {
      // Terminal first, reversed fragments, and duplicates exercise the full core.
      return window.consumeProtocol([...frames].reverse().flatMap(frame => [frame, frame]), protocol);
    }, { frames, protocol });
    expect(text).toBe('Hello 日本語 🌍 '.repeat(400));
  });
}
