import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, openChat, send, toolPart } from "../../support/vercel";

test("frontend handler throws: the error output settles the wait and reaches inference as a failure", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "handler-error");
  await send(page, "Probe the failure.");

  await expectReply(page, 'Done: {"call_throw":{"error":"probe failed"}}');
  await expect(toolPart(page, "call_throw")).toHaveAttribute("data-state", "output-error");

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({
    call_throw: { is_error: true, blocks: [expect.objectContaining({ type: "text", content: "probe failed" })] },
  });
  expect(audit.run).toBeNull();
});
