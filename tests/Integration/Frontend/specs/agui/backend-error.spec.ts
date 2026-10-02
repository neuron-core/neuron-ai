import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { openThread, run } from "../../support/agui";

test("AG-UI error representation: a backend tool failure reaches the client as the content of its result", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "backend-error", "Fail on purpose.");

  const first = await run(agent);
  expect(first.calls.map((call) => call.id)).toEqual(["call_fail_1"]);
  expect(first.reply).toBe('Done: {"call_fail_1":{"error":"clock unavailable"}}');

  const frame = first.resultFrames.call_fail_1;
  expect(frame.content).toBe("clock unavailable");
  expect(frame.error).toBeUndefined();

  // AG-UI defines no `error` on the result event, so the failure travels as content.
  const toolMessage = agent.messages.find((message) => message.role === "tool" && message.toolCallId === "call_fail_1");
  expect(toolMessage?.content).toBe("clock unavailable");
  expect((toolMessage as { error?: string } | undefined)?.error).toBeUndefined();

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1]).call_fail_1).toMatchObject({ is_error: true });
});
