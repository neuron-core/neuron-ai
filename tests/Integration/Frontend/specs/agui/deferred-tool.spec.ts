import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { answer, openThread, run } from "../../support/agui";

test("one deferred tool: the official client receives the call, returns its result, and sees the continuation", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "deferred-title", "What is the title?");

  const first = await run(agent);
  expect(first.calls).toEqual([{ id: "call_read_title_1", name: "read_title", args: {} }]);
  expect(first.reply).toBeUndefined();
  expect(agent.messages.map((message) => message.role)).toEqual(["user", "assistant"]);

  answer(agent, first.calls);
  const second = await run(agent);
  expect(second.reply).toBe('Done: {"call_read_title_1":"Neuron Fixture"}');
  expect(agent.messages.map((message) => message.role)).toEqual(["user", "assistant", "tool", "assistant"]);

  const audit = await observe(request, threadId);
  expect(audit.invocations.map((invocation) => invocation.tools)).toEqual([["read_title", "read_text", "probe"], ["read_title", "read_text", "probe"]]);
  expect(toolResultsSentToProvider(audit.invocations[0])).toEqual({});
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
  expect(audit.run).toBeNull();
});
