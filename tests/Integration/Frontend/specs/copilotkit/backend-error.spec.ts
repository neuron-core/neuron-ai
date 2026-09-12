import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/copilotkit";

test("a backend tool failure reaches the client and inference continues", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "backend-error");
  await send(page, "Fail on purpose.");

  await expectReply(page, 'Done: {"call_fail_1":{"error":"clock unavailable"}}');
  expect(await handlerRuns(page)).toEqual({});

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1]).call_fail_1).toMatchObject({ is_error: true });
});
