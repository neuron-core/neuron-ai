import { test, expect, type APIRequestContext, type Page } from "@playwright/test";
import type Pusher from "pusher-js";
import type { ChannelConsumer } from "@neuron-core/streaming";
import { BACKEND, FRONTEND } from "../../support/backend";

interface Event {
  type: string;
  data: Record<string, any>;
}
interface Frame extends Event {
  streamId: string;
  sequence: number;
}
interface Fixture {
  frames: Frame[];
  wireEvents: Array<{ event: string; data: { nonce: string; ciphertext: string } }>;
  channel: string;
  authorization: { auth: string; shared_secret: string };
  expected: Event[];
  errors: number;
  requests: number;
}
declare global {
  interface Window {
    createChannelConsumer: typeof import("@neuron-core/streaming")["createChannelConsumer"];
    subscribeToPusher: typeof import("@neuron-core/streaming")["subscribeToPusher"];
    channelSubscription: { close(): void };
    channelEvents: Event[];
    channelGaps: string[];
    channelConsumer: ChannelConsumer;
    Pusher: typeof Pusher;
    encryptedChannel: ReturnType<Pusher["subscribe"]>;
    decryptedFrames: Frame[];
  }
}

async function fixture(request: APIRequestContext, transport = "pusher", outcome = "completed", failDelivery = false): Promise<Fixture> {
  const response = await request.post(`${BACKEND}/_test/channels`, { data: { transport, outcome, failDelivery } });
  expect(response.ok(), await response.text()).toBe(true);
  return response.json();
}

async function openConsumer(page: Page, streamId: string): Promise<void> {
  await page.goto(`${FRONTEND}/channels/`);
  await page.waitForFunction(() => typeof window.createChannelConsumer === "function");
  await page.evaluate((id) => {
    window.channelEvents = [];
    window.channelGaps = [];
    window.channelConsumer = window.createChannelConsumer(
      {
        onEvent: ({ type, data }) => window.channelEvents.push({ type, data } as Event),
        onGap: (reason) => window.channelGaps.push(reason),
      },
      id,
    );
  }, streamId);
}

async function deliver(page: Page, frames: Frame[]): Promise<void> {
  await page.evaluate((messages) => {
    for (const frame of messages) window.channelConsumer.accept(frame);
  }, frames);
}

for (const transport of ["pusher", "redis"]) {
  for (const outcome of ["completed", "interrupted", "failed"]) {
    test(`${transport}: reordered events preserve payloads and ${outcome} follows preceding data`, async ({ page, request }) => {
      const { frames, expected, errors } = await fixture(request, transport, outcome);
      expect(errors).toBe(0);
      const fragments = frames.filter((frame) => frame.type === "stream.fragment");
      if (transport === "pusher") {
        expect(new Set(fragments.map((frame) => frame.sequence)).size).toBe(2);
      } else {
        expect(fragments).toHaveLength(0);
      }
      await openConsumer(page, frames[0].streamId);
      // The terminal arrives first; fragments from two same-type events interleave.
      await deliver(page, [frames.at(-1)!]);
      expect(await page.evaluate(() => window.channelEvents)).toEqual([]);
      const shuffled = frames.slice(1, -1).sort((a, b) =>
        Number(b.data.index ?? 0) - Number(a.data.index ?? 0) || b.sequence - a.sequence,
      );
      await deliver(page, shuffled.flatMap((frame) => [frame, frame]));
      expect(await page.evaluate(() => window.channelEvents)).toEqual([]);
      await deliver(page, [frames[0], frames[0]]);
      expect(await page.evaluate(() => window.channelEvents)).toEqual(expected);
      expect(await page.evaluate(() => window.channelGaps)).toEqual([]);
      expect(await page.evaluate(() => window.channelEvents.at(-1)?.data)).toEqual({ workflowId: "workflow-frontend" });
    });
  }
}

test("independent streams sharing a destination cannot mix their fragments", async ({ page, request }) => {
  const first = await fixture(request);
  const second = await fixture(request);
  expect(first.frames[0].streamId).not.toBe(second.frames[0].streamId);
  await openConsumer(page, first.frames[0].streamId);
  const mixed = first.frames.flatMap((frame, index) => [second.frames[index], frame]).filter(Boolean);
  await deliver(page, mixed);
  expect(await page.evaluate(() => window.channelEvents)).toEqual(first.expected);
  expect(await page.evaluate(() => window.channelGaps)).toEqual([]);
});

test("a missing fragment triggers reconciliation without delivering incomplete data or early completion", async ({ page, request }) => {
  const { frames, expected } = await fixture(request);
  await page.clock.install();
  await openConsumer(page, frames[0].streamId);
  await deliver(page, frames.filter((frame) => !(frame.sequence === 1 && frame.data.index === 1)));
  expect(await page.evaluate(() => window.channelEvents)).toEqual([expected[0]]);
  await page.clock.fastForward(30_001);
  expect(await page.evaluate(() => window.channelGaps)).toEqual(["Missing channel events"]);
  await deliver(page, frames);
  expect(await page.evaluate(() => window.channelEvents)).toEqual([expected[0]]);
});

test("a Pusher failure stops ordinary requests but its terminal notification lets the browser detect the gap", async ({ page, request }) => {
  const { frames, errors, requests } = await fixture(request, "pusher", "completed", true);
  expect(errors).toBe(1);
  expect(requests).toBe(2);
  expect(frames).toHaveLength(1);
  expect(frames[0].type).toBe("stream.completed");
  expect(frames[0].sequence).toBeGreaterThan(0);
  await page.clock.install();
  await openConsumer(page, frames[0].streamId);
  await deliver(page, frames);
  expect(await page.evaluate(() => window.channelEvents)).toEqual([]);
  await page.clock.fastForward(30_001);
  expect(await page.evaluate(() => window.channelGaps)).toEqual(["Missing channel events"]);
});

test("incomplete event buffering is bounded and fails into reconciliation", async ({ page, request }) => {
  const { frames } = await fixture(request);
  await openConsumer(page, frames[0].streamId);
  const queued = Array.from({ length: 1025 }, (_, index) => ({ ...frames[0], sequence: index + 1 }));
  await deliver(page, queued);
  expect(await page.evaluate(() => window.channelGaps)).toEqual(["Channel buffer limit exceeded"]);
  expect(await page.evaluate(() => window.channelEvents)).toEqual([]);
});

async function openEncryptedConsumer(page: Page, fixture: Fixture, wrongKey = false): Promise<void> {
  await openConsumer(page, "0".repeat(32));
  await page.evaluate(async ({ channelName, authorization, wrongKey }) => {
    const auth = { ...authorization };
    if (wrongKey) auth.shared_secret = btoa("x".repeat(32));
    const pusher = new window.Pusher("key", {
      cluster: "mt1",
      enabledTransports: [],
      enableStats: false,
      channelAuthorization: { customHandler: (_params, callback) => callback(null, { ...auth }) },
    });
    pusher.disconnect();
    window.encryptedChannel = pusher.subscribe(channelName);
    await new Promise<void>((resolve, reject) => {
      window.encryptedChannel.authorize("123.456", (error) => error ? reject(error) : resolve());
    });
    window.decryptedFrames = [];
    window.channelConsumer.close();
    window.encryptedChannel.bind_global((_name: string, frame: Frame) => window.decryptedFrames.push(frame));
    window.channelSubscription = window.subscribeToPusher(window.encryptedChannel, {
      onEvent: ({ type, data }) => window.channelEvents.push({ type, data } as Event),
      onGap: (reason) => window.channelGaps.push(reason),
    });
  }, { channelName: fixture.channel, authorization: fixture.authorization, wrongKey });
}

async function deliverEncrypted(page: Page, events: Fixture["wireEvents"]): Promise<void> {
  // Inject broker frames at the SDK's socket boundary; its real encryption handler decrypts them.
  await page.evaluate((messages) => {
    for (const message of messages) window.encryptedChannel.handleEvent(message);
  }, events);
}

for (const outcome of ["completed", "interrupted", "failed"]) {
  test(`encrypted Pusher: official browser SDK decrypts fragments and ordered ${outcome}`, async ({ page, request }) => {
    const result = await fixture(request, "pusher-encrypted", outcome);
    expect(result.errors).toBe(0);
    expect(result.wireEvents.some((event) => event.event === "stream.fragment")).toBe(true);
    expect(JSON.stringify(result.wireEvents)).not.toContain("workflow-frontend");
    expect(new Set(result.wireEvents.map((event) => event.data.nonce)).size).toBe(result.wireEvents.length);
    await openEncryptedConsumer(page, result);
    await deliverEncrypted(page, result.wireEvents.slice(1).reverse().flatMap((event) => [event, event]));
    expect(await page.evaluate(() => window.channelEvents)).toEqual([]);
    await deliverEncrypted(page, [result.wireEvents[0]]);
    expect(await page.evaluate(() => window.channelEvents)).toEqual(result.expected);
    expect(await page.evaluate(() => window.channelGaps)).toEqual([]);
  });
}

test("encrypted Pusher: altered ciphertext is rejected and authentic data still works", async ({ page, request }) => {
  const result = await fixture(request, "pusher-encrypted");
  await openEncryptedConsumer(page, result);
  const first = result.wireEvents[0];
  const ciphertext = first.data.ciphertext;
  const altered = { ...first, data: { ...first.data, ciphertext: (ciphertext[0] === "A" ? "B" : "A") + ciphertext.slice(1) } };
  await deliverEncrypted(page, [altered]);
  expect(await page.evaluate(() => window.decryptedFrames)).toEqual([]);
  await deliverEncrypted(page, result.wireEvents);
  expect(await page.evaluate(() => window.channelEvents)).toEqual(result.expected);
});

test("encrypted Pusher: a subscriber with the wrong key receives no plaintext", async ({ page, request }) => {
  const result = await fixture(request, "pusher-encrypted");
  await openEncryptedConsumer(page, result, true);
  await deliverEncrypted(page, [result.wireEvents[0]]);
  expect(await page.evaluate(() => window.decryptedFrames)).toEqual([]);
  expect(await page.evaluate(() => window.channelEvents)).toEqual([]);
});
