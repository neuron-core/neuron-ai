import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";
import { answer, pending, run } from "../../support/agui";

async function requestApproval(request: Parameters<typeof registerThread>[0]) {
  const threadId = await registerThread(request, "approval-title");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "What is the title?" });
  const first = await run(agent);
  expect(first.calls).toEqual([]);
  expect(first.interrupts).toHaveLength(1);
  expect(first.interrupts[0].reason).toBe("confirmation");
  return { threadId, agent, interrupt: first.interrupts[0] };
}

test("approval followed by execution: the tool is dispatched only after approval and runs once", async ({ request }) => {
  const { threadId, agent, interrupt } = await requestApproval(request);
  expect((await observe(request, threadId)).invocations).toHaveLength(1);

  const second = await run(agent, [{ interruptId: interrupt.id, status: "resolved", payload: { approved: true } }]);
  expect(second.calls.map((call) => call.name)).toEqual(["read_title"]);
  expect(second.interrupts).toEqual([]);
  const suspended = await observe(request, threadId);
  expect(suspended.run?.status).toBe("suspended");
  expect(suspended.invocations).toHaveLength(1);

  answer(agent, second.calls);
  const third = await run(agent);
  expect(third.reply).toBe('Done: {"call_read_title_1":"Neuron Fixture"}');

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
  expect(agent.messages.filter((message) => message.role === "tool")).toHaveLength(1);
});

test("approval rejection: the frontend never executes and inference continues with the rejection", async ({ request }) => {
  const { threadId, agent, interrupt } = await requestApproval(request);

  const second = await run(agent, [{ interruptId: interrupt.id, status: "resolved", payload: { approved: false, reason: "Not now" } }]);
  // The rejected call is published together with its rejection result, so a frontend has nothing to execute.
  expect(second.calls.map((call) => call.id)).toEqual(["call_read_title_1"]);
  expect(pending(agent, second.calls)).toEqual([]);
  expect(second.interrupts).toEqual([]);
  expect(second.reply).toMatch(/^Done: /);

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  const results = toolResultsSentToProvider(audit.invocations[1]);
  expect(Object.keys(results)).toEqual(["call_read_title_1"]);
  expect(JSON.stringify(results.call_read_title_1)).toContain("Not now");
  expect(audit.run).toBeNull();

  // A rejection is an instruction to the model, not a tool failure: plain content, no error marker.
  const frame = second.resultFrames.call_read_title_1;
  expect(frame.content).toEqual(expect.stringContaining("Not now"));
  expect(frame.error).toBeUndefined();
  const toolMessages = agent.messages.filter((message) => message.role === "tool");
  expect(toolMessages).toHaveLength(1);
  expect(toolMessages[0].content).toContain("Not now");
});
