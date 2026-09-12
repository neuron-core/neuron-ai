import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { answer, openThread, run } from "../../support/agui";

test("two calls with the same tool name stay distinct by call id", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "same-name", "Read both.");

  const first = await run(agent);
  expect(first.calls).toEqual([
    { id: "call_text_1", name: "read_text", args: { selector: "#first" } },
    { id: "call_text_2", name: "read_text", args: { selector: "#second" } },
  ]);

  answer(agent, first.calls);
  const second = await run(agent);
  expect(second.reply).toBe('Done: {"call_text_1":"Alpha","call_text_2":"Beta"}');

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_text_1: "Alpha", call_text_2: "Beta" });
});
