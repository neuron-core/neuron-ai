import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/vercel";

test("continued conversation: automatic continuation follows each step and stops after the final answer", async ({ page, request }) => {
  const { threadId, requests } = await openChat(page, request, "two-steps");
  await send(page, "Title, then first paragraph.");

  await expectReply(page, 'Done: {"call_read_title_1":"Neuron Fixture","call_text_1":"Alpha"}');
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1, call_text_1: 1 });

  // Settle: no further automatic submission after the final answer.
  await page.waitForTimeout(1000);
  expect(requests).toHaveLength(3);

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(3);
  expect(toolResultsSentToProvider(audit.invocations[2])).toEqual({ call_read_title_1: "Neuron Fixture", call_text_1: "Alpha" });
  expect(audit.run).toBeNull();
});
