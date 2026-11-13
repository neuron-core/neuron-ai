import { createChannelConsumer, isRecord, terminalTypes, type ChannelCallbacks } from './consumer.js';

export interface IQSource {
  readonly readyState: number;
  addEventListener(type: string, listener: (event: { data?: unknown }) => void): unknown;
  removeEventListener(type: string, listener: (event: { data?: unknown }) => void): unknown;
  close(): void;
}

/** EventSource.CLOSED: the browser gave up, and will not reconnect. */
const CLOSED = 2;

/**
 * Subscribe to a stream of an IQ server, whose frames are the envelopes of one segment. The
 * subscription owns the source: it closes it when IQ ends the stream, on a gap, and on close().
 */
export function subscribeToIQ(source: IQSource, callbacks: ChannelCallbacks): { close(): void } {
  let closed = false;
  let whole = false;
  const consumer = createChannelConsumer({
    onEvent(event) {
      whole ||= terminalTypes.has(event.type);
      callbacks.onEvent(event);
    },
    onGap: fail,
  });
  const listeners: Record<string, (event: { data?: unknown }) => void> = {
    message: receive,
    'iq:end': () => end('Missing channel events'),
    'iq:fail': event => end(`IQ stream failed: ${failureReason(event.data)}`),
    'iq:gone': () => end('IQ stream gone'),
    // While the source is connecting again, it resumes after the last frame by itself.
    error: () => source.readyState === CLOSED && end('IQ subscription refused'),
  };

  function close(): void {
    if (closed) return;
    closed = true;
    for (const [type, listener] of Object.entries(listeners)) source.removeEventListener(type, listener);
    source.close();
    consumer.close();
  }

  function fail(reason: string): void {
    if (closed) return;
    close();
    callbacks.onGap(reason);
  }

  /** The source delivers nothing more: a segment still without its terminal event never gets it. */
  function end(reason: string): void {
    if (whole) close();
    else fail(reason);
  }

  function receive(message: { data?: unknown }): void {
    if (closed) return;
    let envelope: unknown;
    try {
      envelope = typeof message.data === 'string' ? JSON.parse(message.data) : undefined;
    } catch {
      envelope = undefined;
    }
    try {
      consumer.accept(envelope);
    } catch (error) {
      close();
      throw error;
    }
  }

  for (const [type, listener] of Object.entries(listeners)) source.addEventListener(type, listener);
  return { close };
}

function failureReason(data: unknown): string {
  try {
    const failure: unknown = typeof data === 'string' ? JSON.parse(data) : undefined;
    if (isRecord(failure) && typeof failure.reason === 'string') return failure.reason;
  } catch {
    // An unreadable failure is a failure all the same.
  }
  return 'unknown';
}
