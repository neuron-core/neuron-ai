import { test, expect } from "@playwright/test";
import { observe } from "../../support/backend";
import { answer, openThread, reload, run } from "../../support/agui";

test("browser reload during approval: a fresh client restores the conversation and answers the persisted approval", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "approval-title", "What is the title?");
  const first = await run(agent);
  expect(first.interrupts).toHaveLength(1);

  const reloaded = await reload(request, threadId);
  expect(reloaded.messages).toEqual([expect.objectContaining({ role: "user", content: "What is the title?" })]);
  expect(reloaded.pendingInterrupts).toEqual(first.interrupts);

  const second = await run(reloaded, [{ interruptId: first.interrupts[0].id, status: "resolved", payload: { approved: true } }]);
  expect(second.calls.map((call) => call.name)).toEqual(["read_title"]);
  answer(reloaded, second.calls);
  const third = await run(reloaded);
  expect(third.reply).toBe('Done: {"call_read_title_1":"Neuron Fixture"}');
});

test("browser reload while a frontend call awaits its result: the reloaded client resolves the persisted wait", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "deferred-title", "What is the title?");
  const first = await run(agent);
  // Live, the call is dispatched without an interrupt: the client is about to execute it.
  expect(first.calls.map((call) => call.id)).toEqual(["call_read_title_1"]);
  expect(first.interrupts).toEqual([]);

  const reloaded = await reload(request, threadId);
  // Neuron created the message holding the call, so it keeps the ID the client rendered.
  expect(reloaded.messages[1]).toMatchObject({ id: agent.messages[1].id, role: "assistant", toolCalls: [{ id: "call_read_title_1" }] });
  expect(reloaded.pendingInterrupts).toEqual([expect.objectContaining({ reason: "neuron:wait_for_event" })]);

  const second = await run(reloaded, [{
    interruptId: reloaded.pendingInterrupts[0].id,
    status: "resolved",
    payload: { call_read_title_1: { result: "Neuron Fixture" } },
  }]);
  expect(second.reply).toBe('Done: {"call_read_title_1":"Neuron Fixture"}');
  expect((await observe(request, threadId)).run).toBeNull();

  // Every message the client now holds was rendered from stored IDs, so another reload rebuilds the same list.
  const again = await reload(request, threadId);
  expect(again.messages.map((message) => message.id)).toEqual(reloaded.messages.map((message) => message.id));
  expect(again.pendingInterrupts).toEqual([]);
});

test("browser reload while a frontend call awaits its result: cancelling the wait settles the call as an error", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "deferred-title", "What is the title?");
  await run(agent);

  const reloaded = await reload(request, threadId);
  const second = await run(reloaded, [{ interruptId: reloaded.pendingInterrupts[0].id, status: "cancelled" }]);

  expect(second.reply).toBe('Done: {"call_read_title_1":{"error":"Frontend tool execution cancelled."}}');
  expect((await observe(request, threadId)).run).toBeNull();
});
