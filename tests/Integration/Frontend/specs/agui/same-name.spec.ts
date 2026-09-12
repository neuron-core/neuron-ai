import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";
import { answer, run } from "../../support/agui";

test("two calls with the same tool name stay distinct by call id", async ({ request }) => {
  const threadId = await registerThread(request, "same-name");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "Read both." });

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
