import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";
import { answer, pending, run } from "../../support/agui";

test("partial and out-of-order results: an accepted result is kept, pending work stays visible, nothing repeats", async ({ request }) => {
  const threadId = await registerThread(request, "same-name");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "Read both." });

  const first = await run(agent);
  expect(first.calls.map((call) => call.id)).toEqual(["call_text_1", "call_text_2"]);

  // Answer the second call first and submit before the first one is done.
  const executed = answer(agent, first.calls.filter((call) => call.id === "call_text_2"));
  expect(executed.map((call) => call.id)).toEqual(["call_text_2"]);
  const second = await run(agent);
  expect(second.reply).toBeUndefined();
  expect(second.interrupts).toEqual([]);
  expect(pending(agent, [...first.calls, ...second.calls]).map((call) => call.id)).toEqual(["call_text_1"]);

  const partial = await observe(request, threadId);
  expect(partial.run?.status).toBe("suspended");
  expect(partial.invocations).toHaveLength(1);

  expect(answer(agent, first.calls).map((call) => call.id)).toEqual(["call_text_1"]);
  const third = await run(agent);
  expect(third.reply).toBe('Done: {"call_text_1":"Alpha","call_text_2":"Beta"}');

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_text_1: "Alpha", call_text_2: "Beta" });
  expect(agent.messages.filter((message) => message.role === "tool")).toHaveLength(2);
});
