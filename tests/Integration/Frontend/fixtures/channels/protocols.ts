import { AbstractAgent } from '@ag-ui/client';
import type { BaseEvent, RunAgentInput } from '@ag-ui/core';
import { EventSchemas } from '@ag-ui/core/schemas';
import { Observable } from 'rxjs';
import { readUIMessageStream, uiMessageChunkSchema } from 'ai';
import { createChannelConsumer, createProtocolStream, subscribeToMercure } from '@neuron-core/streaming';

/** Frames are envelopes handed to the core, or the data of Mercure updates dispatched as an EventSource does. */
export async function consumeProtocol(frames: unknown[], protocol: 'agui' | 'vercel', transport: 'core' | 'mercure' = 'core'): Promise<string> {
  let feed!: () => void;
  const subscribe = (callbacks: Parameters<typeof createChannelConsumer>[0]) => {
    if (transport === 'mercure') {
      const source = new EventTarget();
      feed = () => frames.forEach(data => source.dispatchEvent(new MessageEvent('message', { data })));
      return subscribeToMercure(source as unknown as EventSource, callbacks);
    }
    const consumer = createChannelConsumer(callbacks);
    feed = () => frames.forEach(frame => consumer.accept(frame));
    return consumer;
  };
  if (protocol === 'vercel') {
    const stream = createProtocolStream(subscribe, async event => {
      const result = await uiMessageChunkSchema().validate!(event);
      if (!result.success) throw result.error;
      return result.value;
    });
    feed();
    let text = '';
    for await (const message of readUIMessageStream({ stream, terminateOnError: true })) {
      text = message.parts.filter(part => part.type === 'text').map(part => part.text).join('');
    }
    return text;
  }

  class ChannelAgent extends AbstractAgent {
    run(_input: RunAgentInput): Observable<BaseEvent> {
      return new Observable(observer => {
        const stream = createProtocolStream(subscribe, event => EventSchemas.parse(event));
        const reader = stream.getReader();
        void (async () => {
          try {
            while (!observer.closed) {
              const { done, value } = await reader.read();
              if (done) { observer.complete(); return; }
              observer.next(value);
            }
          } catch (error) {
            observer.error(error);
          } finally {
            reader.releaseLock();
          }
        })();
        feed();
        return () => { void reader.cancel().catch(() => {}); };
      });
    }
  }
  const agent = new ChannelAgent({ threadId: 'thread-channel' });
  const result = await agent.runAgent({ runId: 'run-channel' });
  return result.newMessages.filter(message => message.role === 'assistant').map(message => message.content).join('');
}

Object.assign(window, { consumeProtocol });
