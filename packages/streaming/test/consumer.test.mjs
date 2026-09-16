import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createChannelConsumer, createProtocolStream, subscribeToPusher } from '../dist/index.js';

const id = 'a'.repeat(32);
const frame = (sequence, data = { value: sequence }, type = 'text-delta', streamId = id) => ({ streamId, sequence, type, data });
const fragment = (sequence, part, index = 0, total = 1) => frame(sequence, { event: 'text-delta', index, total, part }, 'stream.fragment');
function setup() {
  const events = [], gaps = [];
  const consumer = createChannelConsumer({ onEvent: event => events.push(event), onGap: reason => gaps.push(reason) }, id);
  return { ...consumer, events, gaps };
}
function pusher() {
  const listeners = new Set();
  return {
    listeners,
    bind_global(callback) { listeners.add(callback); },
    unbind_global(callback) { listeners.delete(callback); },
    send(value, name = value.type) { for (const listener of [...listeners]) listener(name, value); },
  };
}

test('orders interleaved fragments, decodes UTF-8, and waits before terminal delivery', () => {
  const c = setup();
  const encoded = Buffer.from(JSON.stringify({ text: 'こんにちは 🌍' })).toString('base64url');
  c.accept(frame(2, { workflowId: 'workflow' }, 'stream.completed'));
  c.accept(fragment(1, encoded.slice(4), 1, 2));
  c.accept(fragment(1, encoded.slice(4), 1, 2));
  c.accept(fragment(1, encoded.slice(0, 4), 0, 2));
  assert.deepEqual(c.events, []);
  c.accept(frame(0));
  c.accept(frame(3));
  assert.deepEqual(c.events.map(e => e.data), [{ value: 0 }, { text: 'こんにちは 🌍' }, { workflowId: 'workflow' }]);
  assert.deepEqual(c.gaps, []);
});

test('ignores other segments and already delivered duplicates', () => {
  const c = setup();
  c.accept(frame(0, {}, 'text-delta', 'b'.repeat(32)));
  c.accept(frame(0));
  c.accept(frame(0));
  assert.equal(c.events.length, 1);
  c.close();
});

test('later events and duplicate fragments cannot renew a gap deadline', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const c = setup();
  c.accept(fragment(0, 'e30=', 1, 2));
  t.mock.timers.tick(20_000);
  c.accept(frame(1));
  c.accept(fragment(0, 'e30=', 1, 2));
  t.mock.timers.tick(10_000);
  assert.deepEqual(c.gaps, ['Missing channel events']);
  c.accept(frame(0));
  assert.deepEqual(c.events, []);
});

test('advancing the expected sequence starts a fresh deadline', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const c = setup();
  c.accept(frame(2));
  t.mock.timers.tick(20_000);
  c.accept(frame(0));
  t.mock.timers.tick(20_000);
  assert.deepEqual(c.gaps, []);
  t.mock.timers.tick(10_000);
  assert.deepEqual(c.gaps, ['Missing channel events']);
});

for (const [label, first, conflict] of [
  ['payload', frame(1), frame(1, { changed: true })],
  ['type', frame(1), frame(1, { value: 1 }, 'other')],
  ['fragment content', fragment(1, 'e30='), fragment(1, 'W10=')],
  ['fragment total', fragment(1, 'e30='), fragment(1, 'e30=', 1, 2)],
  ['representation', fragment(1, 'e30='), frame(1, {})],
]) test(`rejects conflicting queued ${label}`, () => {
  const c = setup(); c.accept(first); c.accept(conflict);
  assert.deepEqual(c.gaps, ['Conflicting channel events']);
  c.accept(frame(0)); assert.deepEqual(c.events, []);
});

for (const invalid of [
  { ...frame(0), sequence: -1 }, { ...frame(0), sequence: 0.5 },
  { ...frame(0), sequence: Number.MAX_SAFE_INTEGER + 1 }, { ...frame(0), type: '' },
  { streamId: id, sequence: 0, type: 'event' },
  fragment(0, '!'), fragment(0, 'e30=', 0, 2), fragment(0, 'e30=', 0, 4097),
  fragment(0, 'e30=', -1), fragment(0, 'e30=', 1),
  fragment(0, 'a'), fragment(0, Buffer.from('not JSON').toString('base64url')),
  fragment(0, Buffer.from([0xff]).toString('base64url')),
]) test(`rejects malformed envelope or fragment ${JSON.stringify(invalid)}`, () => {
  const c = setup(); c.accept(invalid); c.accept(frame(0));
  assert.equal(c.gaps.length, 1); assert.deepEqual(c.events, []);
});

test('bounds queued events and encoded bytes', () => {
  const c = setup();
  for (let sequence = 1; sequence <= 1025; ++sequence) c.accept(frame(sequence));
  assert.deepEqual(c.gaps, ['Channel buffer limit exceeded']);
  const large = setup(); large.accept(frame(0, 'a'.repeat(8 * 1024 * 1024)));
  assert.deepEqual(large.gaps, ['Channel buffer limit exceeded']);
});

test('snapshots a payload instead of retaining mutable caller objects', () => {
  const c = setup(), data = { value: 1 };
  c.accept(frame(1, data)); data.value = 99; c.accept(frame(0));
  assert.deepEqual(c.events[1].data, { value: 1 }); c.close();
});

test('callbacks may feed more frames or close without recursive delivery', () => {
  const sequences = [];
  const c = createChannelConsumer({ onEvent: event => {
    sequences.push(event.data.value);
    if (event.data.value === 0) { c.accept(frame(1)); sequences.push('after accept'); }
    if (event.data.value === 1) c.close();
  }, onGap: assert.fail }, id);
  c.accept(frame(2)); c.accept(frame(0));
  assert.deepEqual(sequences, [0, 'after accept', 1]);
});

test('close and callback errors release timers and stop delivery', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const c = setup(); c.accept(frame(1)); c.close(); c.close(); t.mock.timers.tick(60_000);
  assert.deepEqual(c.gaps, []);
  const throwing = createChannelConsumer({ onEvent() { throw new Error('application'); }, onGap: assert.fail }, id);
  throwing.accept(frame(2));
  assert.throws(() => throwing.accept(frame(0)), /application/);
  throwing.accept(frame(1)); t.mock.timers.tick(60_000);
});

test('Pusher routes simultaneous streams and suppresses completed stream duplicates', () => {
  const channel = pusher(), events = [];
  const subscription = subscribeToPusher(channel, { onEvent: e => events.push(e), onGap: assert.fail });
  channel.send({}, 'pusher:subscription_succeeded');
  channel.send(frame(1, {}, 'stream.completed'));
  channel.send(frame(0, { second: true }, 'stream.completed', 'b'.repeat(32)));
  channel.send(frame(0)); channel.send(frame(0));
  assert.equal(events.length, 3);
  subscription.close(); assert.equal(channel.listeners.size, 0);
});

test('Pusher shares its event budget and detaches only its own listener on failure', () => {
  const channel = pusher(), gaps = [], unrelated = () => {};
  channel.bind_global(unrelated);
  const subscription = subscribeToPusher(channel, { onEvent: assert.fail, onGap: reason => gaps.push(reason) });
  for (let i = 0; i < 1025; ++i) channel.send(frame(i + 1, {}, 'event', (i % 2 ? 'a' : 'b').repeat(32)));
  assert.deepEqual(gaps, ['Channel buffer limit exceeded']);
  assert.deepEqual([...channel.listeners], [unrelated]); subscription.close();
});

test('Pusher bounds active streams and remembered finished streams', () => {
  for (const completed of [false, true]) {
    const channel = pusher(), gaps = [];
    subscribeToPusher(channel, { onEvent() {}, onGap: reason => gaps.push(reason) });
    const limit = completed ? 1024 : 64;
    for (let i = 0; i <= limit; ++i) channel.send(frame(completed ? 0 : 1, {}, completed ? 'stream.completed' : 'event', i.toString(16).padStart(32, '0')));
    assert.deepEqual(gaps, ['Channel stream limit exceeded']);
    assert.equal(channel.listeners.size, 0);
  }
});

test('Pusher rejects mismatched event names and cleans up if the application throws', () => {
  const channel = pusher(), gaps = [];
  subscribeToPusher(channel, { onEvent: assert.fail, onGap: reason => gaps.push(reason) });
  channel.send(frame(0), 'wrong'); assert.deepEqual(gaps, ['Invalid channel envelope']);
  subscribeToPusher(channel, { onEvent() { throw new Error('application'); }, onGap: assert.fail });
  assert.throws(() => channel.send(frame(0)), /application/); assert.equal(channel.listeners.size, 0);
});

test('preserves scalar payloads and reports unserializable payloads once', () => {
  const c = setup();
  const values = [false, 0, null, 'text', ['nested']];
  values.forEach((value, sequence) => c.accept(frame(sequence, value)));
  assert.deepEqual(c.events.map(e => e.data), values); c.close();
  const cyclic = {}; cyclic.self = cyclic;
  for (const value of [undefined, cyclic, 1n]) {
    let calls = 0;
    const consumer = createChannelConsumer({ onEvent: assert.fail, onGap() { ++calls; throw new Error('recovery'); } }, id);
    assert.throws(() => consumer.accept({ ...frame(0), data: value }), /recovery/);
    assert.equal(calls, 1);
    consumer.accept(frame(0));
  }
});

test('bounds aggregate parts, not just the number of events', () => {
  const c = setup();
  for (let index = 0; index < 4095; ++index) c.accept(fragment(1, 'AAAA', index, 4096));
  c.accept(frame(2)); c.accept(frame(3));
  assert.deepEqual(c.gaps, ['Channel buffer limit exceeded']);
});

test('Pusher gap closes all streams and cancels their timers', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const channel = pusher(), gaps = [];
  subscribeToPusher(channel, { onEvent: assert.fail, onGap: reason => gaps.push(reason) });
  channel.send(frame(1)); channel.send(frame(1, {}, 'event', 'b'.repeat(32)));
  t.mock.timers.tick(60_000);
  assert.deepEqual(gaps, ['Missing channel events']);
  assert.equal(channel.listeners.size, 0);
});

test('buffer accounting includes event names and multi-byte payloads', () => {
  for (const [type, data] of [['a'.repeat(8 * 1024 * 1024), {}], ['event', '世'.repeat(3 * 1024 * 1024)]]) {
    const c = setup(); c.accept(frame(1, data, type));
    assert.deepEqual(c.gaps, ['Channel buffer limit exceeded']);
  }
});

test('the core discovers segments, preserves identity, and suppresses finished duplicates', () => {
  const events = [], gaps = [];
  const c = createChannelConsumer({ onEvent: e => events.push(e), onGap: reason => gaps.push(reason) });
  const other = 'b'.repeat(32);
  c.accept(frame(1, {}, 'stream.completed'));
  c.accept(frame(0, {}, 'stream.interrupted', other));
  c.accept(frame(0));
  c.accept(frame(0));
  c.accept(frame(0, {}, 'stream.interrupted', other));
  assert.deepEqual(events, [frame(0, {}, 'stream.interrupted', other), frame(0), frame(1, {}, 'stream.completed')]);
  assert.deepEqual(gaps, []);
  c.close();
});

test('the core shares limits across segments and closes the whole receiver on a gap', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const gaps = [];
  const c = createChannelConsumer({ onEvent: assert.fail, onGap: reason => gaps.push(reason) });
  for (let i = 0; i <= 1024; ++i) c.accept(frame(i + 1, {}, 'event', (i % 2 ? 'a' : 'b').repeat(32)));
  assert.deepEqual(gaps, ['Channel buffer limit exceeded']);
  t.mock.timers.tick(60_000);
  c.accept(frame(0));
  assert.equal(gaps.length, 1);
});

test('the neutral consumer rejects malformed input and invalid selection', () => {
  assert.throws(() => createChannelConsumer({ onEvent() {}, onGap() {} }, 'invalid'), /stream ID/);
  for (const value of [null, [], {}, { streamId: 'invalid' }]) {
    const gaps = [];
    const c = createChannelConsumer({ onEvent: assert.fail, onGap: reason => gaps.push(reason) });
    c.accept(value); c.accept(value);
    assert.deepEqual(gaps, ['Invalid channel envelope']);
  }
});

test('Pusher can select one segment before a protocol bridge consumes it', () => {
  const channel = pusher(), events = [];
  const subscription = subscribeToPusher(channel, { onEvent: e => events.push(e), onGap: assert.fail }, id);
  channel.send(frame(0, {}, 'event', 'b'.repeat(32)));
  channel.send(frame(0));
  assert.deepEqual(events, [frame(0)]);
  subscription.close();
});

function protocol(parse = event => event) {
  let consumer, closes = 0;
  const stream = createProtocolStream(callbacks => {
    consumer = createChannelConsumer(callbacks);
    return { close() { ++closes; consumer.close(); } };
  }, parse);
  return { stream, accept: value => consumer.accept(value), closes: () => closes };
}

async function collect(stream) {
  const values = [];
  for await (const value of stream) values.push(value);
  return values;
}

for (const outcome of ['completed', 'interrupted']) {
  test(`protocol streams decode fragmented payloads, validate in order, and close on ${outcome}`, async () => {
    const c = protocol(async event => { await Promise.resolve(); return event; });
    c.accept(frame(2, {}, `stream.${outcome}`));
    c.accept(fragment(1, Buffer.from(JSON.stringify({ delta: '日本語 🌍', type: 'payload-type' })).toString('base64url')));
    c.accept(frame(0, { id: 'part-1' }, 'text-start'));
    assert.deepEqual(await collect(c.stream), [
      { id: 'part-1', type: 'text-start' },
      { delta: '日本語 🌍', type: 'text-delta' },
    ]);
    assert.equal(c.closes(), 1);
  });
}

test('protocol failure follows preceding protocol events', async () => {
  const c = protocol(), reader = c.stream.getReader();
  c.accept(frame(0, { errorText: 'Failure' }, 'error'));
  c.accept(frame(1, {}, 'stream.failed'));
  assert.deepEqual((await reader.read()).value, { type: 'error', errorText: 'Failure' });
  await assert.rejects(reader.read(), /Backend stream failed/);
  assert.equal(c.closes(), 1);
});

test('protocol streams release subscriptions and pending gap timers on cancellation', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const c = protocol(); c.accept(frame(1));
  await c.stream.cancel();
  t.mock.timers.tick(60_000);
  assert.equal(c.closes(), 1);
});

for (const parse of [() => { throw new Error('Invalid SDK event'); }, async () => { throw new Error('Invalid SDK event'); }]) {
  test('protocol validation failures cancel upstream consumption', async () => {
    const c = protocol(parse);
    c.accept(frame(0));
    await assert.rejects(collect(c.stream), /Invalid SDK event/);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(c.closes(), 1);
  });
}

test('protocol streams reject non-object data and mixed segments', async () => {
  const scalar = protocol(); scalar.accept(frame(0, false));
  await assert.rejects(collect(scalar.stream), /payload must be an object/);
  const mixed = protocol(); mixed.accept(frame(0)); mixed.accept(frame(0, {}, 'event', 'b'.repeat(32)));
  await assert.rejects(collect(mixed.stream), /one execution segment/);
  assert.equal(mixed.closes(), 1);
});

test('protocol streams report gaps and stop a slow reader from buffering indefinitely', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const missing = protocol(); missing.accept(frame(1));
  t.mock.timers.tick(30_000);
  await assert.rejects(collect(missing.stream), /Missing channel events/);
  assert.equal(missing.closes(), 1);
  for (const payload of [{ small: true }, { large: 'a'.repeat(1024 * 1024) }]) {
    const c = protocol();
    for (let sequence = 0; sequence <= 1024 && !c.closes(); ++sequence) c.accept(frame(sequence, payload));
    await assert.rejects(collect(c.stream), /Protocol stream buffer limit exceeded/);
    assert.equal(c.closes(), 1);
  }
});

test('protocol subscriptions can finish or fail synchronously during setup', async () => {
  for (const fail of [false, true]) {
    let closes = 0;
    const stream = createProtocolStream(callbacks => {
      if (fail) callbacks.onGap('Disconnected');
      else callbacks.onEvent(frame(0, {}, 'stream.completed'));
      return { close() { ++closes; } };
    }, event => event);
    if (fail) await assert.rejects(collect(stream), /Disconnected/);
    else assert.deepEqual(await collect(stream), []);
    assert.equal(closes, 1);
  }
  const stream = createProtocolStream(() => { throw new Error('Cannot subscribe'); }, event => event);
  await assert.rejects(collect(stream), /Cannot subscribe/);
});


test('protocol streams accept PHP empty payloads but reject nonempty lists', async () => {
  const empty = protocol();
  empty.accept(frame(0, [], 'start-step'));
  empty.accept(frame(1, [], 'finish-step'));
  empty.accept(frame(2, {}, 'stream.completed'));
  assert.deepEqual(await collect(empty.stream), [{ type: 'start-step' }, { type: 'finish-step' }]);
  const list = protocol(); list.accept(frame(0, ['unexpected']));
  await assert.rejects(collect(list.stream), /payload must be an object/);
});
