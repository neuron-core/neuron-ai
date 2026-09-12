import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";
import { answer, run } from "../../support/agui";

test("frontend handler throws: the tool message error settles the wait and reaches inference as a failure", async ({ request }) => {
  const threadId = await registerThread(request, "handler-error");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "Probe the failure." });

  const first = await run(agent);
  expect(first.calls.map((call) => call.id)).toEqual(["call_throw"]);

  answer(agent, first.calls);
  const second = await run(agent);
  expect(second.reply).toBe('Done: {"call_throw":{"error":"probe failed"}}');

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({
    call_throw: { is_error: true, blocks: [expect.objectContaining({ type: "text", content: "probe failed" })] },
  });
  expect(audit.run).toBeNull();
});
