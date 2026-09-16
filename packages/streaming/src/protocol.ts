import { isRecord, type ChannelCallbacks, type ChannelEvent } from './consumer.js';

export interface ProtocolEvent {
  type: string;
  [key: string]: unknown;
}

/** Bridge one reconciled segment to a typed protocol stream. */
export function createProtocolStream<T>(
  subscribe: (callbacks: ChannelCallbacks) => { close(): void },
  parse: (event: ProtocolEvent) => T | Promise<T>,
): ReadableStream<T> {
  let subscription: { close(): void } | undefined;
  let closed = false;
  let streamId: string | undefined;

  function close(): void {
    if (closed) return;
    closed = true;
    subscription?.close();
  }

  const events = new ReadableStream<ChannelEvent>({
    start(controller) {
      function fail(error: unknown): void {
        if (closed) return;
        close();
        controller.error(error);
      }
      try {
        subscription = subscribe({
          onEvent(event) {
            if (closed) return;
            streamId ??= event.streamId;
            if (event.streamId !== streamId) return fail(new Error('Protocol streams require one execution segment'));
            // Push transports cannot be paused; bound the extra queue for slow readers.
            const size = eventBytes(event);
            if (size > (controller.desiredSize ?? 0)) return fail(new Error('Protocol stream buffer limit exceeded'));
            controller.enqueue(event);
            if (isTerminal(event.type)) {
              close();
              controller.close();
            }
          },
          onGap: reason => fail(new Error(reason)),
        });
        // A source may finish synchronously before returning its cleanup handle.
        if (closed) subscription.close();
      } catch (error) {
        fail(error);
      }
    },
    cancel: close,
  }, {
    highWaterMark: 8 * 1024 * 1024,
    size: eventBytes,
  });

  return events.pipeThrough(new TransformStream<ChannelEvent, T>({
    async transform(event, controller) {
      if (event.type === 'stream.failed') throw new Error('Backend stream failed');
      if (isTerminal(event.type)) return;
      // PHP encodes ProtocolEvent's default empty payload as [], including Vercel step boundaries.
      if (!isRecord(event.data) && !(Array.isArray(event.data) && event.data.length === 0)) {
        throw new TypeError('Protocol event payload must be an object');
      }
      // The envelope owns the discriminator, even if the payload contains a type field.
      controller.enqueue(await parse({ ...event.data, type: event.type }));
    },
  }));
}

function isTerminal(type: string): boolean {
  return type === 'stream.completed' || type === 'stream.interrupted' || type === 'stream.failed';
}

function eventBytes(event: ChannelEvent): number {
  return Math.max(8192, new TextEncoder().encode(JSON.stringify(event)).length);
}
