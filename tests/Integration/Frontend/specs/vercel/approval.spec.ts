import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send, toolPart } from "../../support/vercel";

test("approval followed by execution: the handler runs once, only after approval", async ({ page, request }) => {
  const { threadId, requests } = await openChat(page, request, "approval-title");
  await send(page, "What is the title?");

  const part = toolPart(page, "call_read_title_1");
  await expect(part).toHaveAttribute("data-state", "approval-requested");
  expect(await handlerRuns(page)).toEqual({});
  expect((await observe(request, threadId)).invocations).toHaveLength(1);

  await part.getByRole("button", { name: "Approve" }).click();
  await expectReply(page, 'Done: {"call_read_title_1":"Neuron Fixture"}');
  await expect(part).toHaveAttribute("data-state", "output-available");
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
  expect(audit.run).toBeNull();
  expect(requests).toHaveLength(3);
});

test("approval rejection: the handler never runs and inference continues with the rejection", async ({ page, request }) => {
  const { threadId, requests } = await openChat(page, request, "approval-title");
  await send(page, "What is the title?");

  const part = toolPart(page, "call_read_title_1");
  await expect(part).toHaveAttribute("data-state", "approval-requested");
  await part.getByRole("button", { name: "Reject" }).click();

  await expect(page.locator('[data-role="assistant"] [data-part="text"]').last()).toContainText("Done: ");
  await expect(part).toHaveAttribute("data-state", "output-denied");
  expect(await handlerRuns(page)).toEqual({});

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(JSON.stringify(toolResultsSentToProvider(audit.invocations[1]).call_read_title_1)).toContain("Not now");
  expect(audit.run).toBeNull();
  expect(requests).toHaveLength(2);
});
