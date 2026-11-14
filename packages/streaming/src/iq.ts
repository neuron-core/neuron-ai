import { subscribe, type Outcome, type SubscribeOptions, type Target } from '@neuron-core/iq-stream/client';
import { createChannelConsumer, terminalTypes, type ChannelCallbacks } from './consumer.js';

/**
 * Subscribe to a stream of an IQ server, whose frames are the envelopes of one segment. `target`
 * and `options` are those of the IQ client's `subscribe`: a subscribe URL, or a function that
 * returns the source to read. The client owns the source, and closes it when IQ ends the stream,
 * on a gap, and on close().
 */
export function subscribeToIQ(target: Target, callbacks: ChannelCallbacks, options?: SubscribeOptions): { close(): void } {
  let closed = false;
  let whole = false;
  const consumer = createChannelConsumer({
    onEvent(event) {
      whole ||= terminalTypes.has(event.type);
      callbacks.onEvent(event);
    },
    onGap: fail,
  });
  const subscription = subscribe(target, {
    onFrame({ data }) {
      let envelope: unknown;
      try {
        envelope = JSON.parse(data);
      } catch {
        envelope = undefined;
      }
      consumer.accept(envelope);
    },
    // The source delivers nothing more: a segment still without its terminal event never gets it.
    onClose(outcome) {
      if (outcome.type === 'closed' || whole) return close();
      fail(gapReason(outcome));
    },
  }, options);

  function close(): void {
    if (closed) return;
    closed = true;
    subscription.close();
    consumer.close();
  }

  function fail(reason: string): void {
    if (closed) return;
    close();
    callbacks.onGap(reason);
  }

  return { close };
}

function gapReason(outcome: Exclude<Outcome, { type: 'closed' }>): string {
  if (outcome.type === 'end') return 'Missing channel events';
  if (outcome.type === 'fail') return `IQ stream failed: ${outcome.reason}`;
  return outcome.type === 'gone' ? 'IQ stream gone' : 'IQ subscription refused';
}
