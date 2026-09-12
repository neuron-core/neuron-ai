import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/copilotkit";

test("two calls with the same tool name stay distinct by call id", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "same-name");
  await send(page, "Read both.");

  await expectReply(page, 'Done: {"call_text_1":"Alpha","call_text_2":"Beta"}');
  expect(await handlerRuns(page)).toEqual({ call_text_1: 1, call_text_2: 1 });

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_text_1: "Alpha", call_text_2: "Beta" });
});
