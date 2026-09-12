import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { openThread, run } from "../../support/agui";

test("AG-UI error representation: a backend tool failure is marked on the wire but the official client keeps only its content", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "backend-error", "Fail on purpose.");

  const first = await run(agent);
  expect(first.calls.map((call) => call.id)).toEqual(["call_fail_1"]);
  expect(first.reply).toBe('Done: {"call_fail_1":{"error":"clock unavailable"}}');

  const frame = first.resultFrames.call_fail_1;
  expect(frame.content).toBe("clock unavailable");
  expect(frame.error).toBe("clock unavailable");

  // The client's event reducer builds the tool message from `content` alone; the
  // `error` marker survives the frame (schema passthrough) but not the message state.
  const toolMessage = agent.messages.find((message) => message.role === "tool" && message.toolCallId === "call_fail_1");
  expect(toolMessage?.content).toBe("clock unavailable");
  expect((toolMessage as { error?: string } | undefined)?.error).toBeUndefined();

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1]).call_fail_1).toMatchObject({ is_error: true });
});
