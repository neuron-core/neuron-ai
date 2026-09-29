import { test, expect } from "@playwright/test";
import { LONG_STORY, observe } from "../../support/backend";
import { expectReply, openChat, send } from "../../support/vercel";

test("a tab closed mid-stream does not lock the thread: a new tab continues the conversation at once", async ({ context, page, request }) => {
  const { threadId } = await openChat(page, request, "abandoned-stream");
  await send(page, LONG_STORY);
  await expect(page.locator('[data-role="assistant"] [data-part="text"]').last()).toContainText("Once upon a time.");
  await page.close();

  const left = await observe(request, threadId);
  expect(left.run?.status).toBe("failed");

  const reopened = await context.newPage();
  await openChat(reopened, request, "abandoned-stream", { thread: threadId });
  await send(reopened, "Are you still there?");
  await expectReply(reopened, "Done: []");
});
