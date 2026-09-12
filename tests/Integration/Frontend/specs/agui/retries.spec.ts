import { test, expect, type APIRequestContext } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import type { Message } from "@ag-ui/client";
import { BACKEND, observe } from "../../support/backend";
import { answer, openThread, run } from "../../support/agui";

async function firstStep(request: APIRequestContext, scenario: string, prompt: string) {
  const { threadId, agent } = await openThread(request, scenario, prompt);
  return { threadId, agent, calls: (await run(agent)).calls };
}

function replay(threadId: string, messages: Message[]): HttpAgent {
  return new HttpAgent({ url: `${BACKEND}/agui`, threadId, initialMessages: structuredClone(messages) });
}

test("repeated identical input while the batch is pending is accepted without repeating work", async ({ request }) => {
  const { threadId, agent, calls } = await firstStep(request, "same-name", "Read both.");
  answer(agent, calls.filter((call) => call.id === "call_text_2"));
  const snapshot = structuredClone(agent.messages);

  await run(agent);
  await run(replay(threadId, snapshot)); // the same partial submission delivered again
  const audit = await observe(request, threadId);
  expect(audit.run?.status).toBe("suspended");
  expect(audit.invocations).toHaveLength(1);
});

test("a conflicting result for an already settled call is rejected before execution", async ({ request }) => {
  const { threadId, agent, calls } = await firstStep(request, "same-name", "Read both.");
  answer(agent, calls.filter((call) => call.id === "call_text_1"));
  await run(agent);

  const conflicting = replay(threadId, agent.messages.filter((message) => message.role !== "tool"));
  conflicting.addMessage({ id: "result-call_text_1-b", role: "tool", toolCallId: "call_text_1", content: "Gamma" });
  conflicting.addMessage({ id: "result-call_text_2", role: "tool", toolCallId: "call_text_2", content: "Beta" });
  await expect(run(conflicting)).rejects.toThrow(/HTTP 409.*different outcome|HTTP 400.*Conflicting/);

  const audit = await observe(request, threadId);
  expect(audit.run?.status).toBe("suspended");
  expect(audit.invocations).toHaveLength(1);
});

test("a stale request delivered after the run completed is rejected, never restarted as a new turn", async ({ request }) => {
  const { threadId, agent, calls } = await firstStep(request, "deferred-title", "What is the title?");
  answer(agent, calls);
  const snapshot = structuredClone(agent.messages);
  const second = await run(agent);
  expect(second.reply).toBe('Done: {"call_read_title_1":"Neuron Fixture"}');

  await expect(run(replay(threadId, snapshot))).rejects.toThrow(/HTTP 400.*no persisted run/);
  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
});

test("an input from a previous generation cannot settle the next turn's wait", async ({ request }) => {
  const { threadId, agent, calls } = await firstStep(request, "deferred-title", "What is the title?");
  answer(agent, calls);
  const staleSubmission = structuredClone(agent.messages);
  await run(agent);

  agent.addMessage({ id: "user-2", role: "user", content: "And again?" });
  const secondTurn = await run(agent);
  expect(secondTurn.calls.map((call) => call.id)).toEqual(["call_read_title_1_t2"]);

  await expect(run(replay(threadId, staleSubmission))).rejects.toThrow(/HTTP 400.*no matching continuation/);
  const audit = await observe(request, threadId);
  expect(audit.run?.status).toBe("suspended");
  expect(audit.invocations).toHaveLength(3);
});
