import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send, toolPart } from "../../support/vercel";

test("browser reload: saved frontend context reconnects to the persisted wait", async ({ page, request }) => {
  const { threadId, requests } = await openChat(page, request, "approval-title");
  await send(page, "What is the title?");
  await expect(toolPart(page, "call_read_title_1")).toHaveAttribute("data-state", "approval-requested");

  await page.reload();
  const part = toolPart(page, "call_read_title_1");
  await expect(part).toHaveAttribute("data-state", "approval-requested");
  expect(await handlerRuns(page)).toEqual({});

  await part.getByRole("button", { name: "Approve" }).click();
  await expectReply(page, 'Done: {"call_read_title_1":"Neuron Fixture"}');
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
  expect(requests).toHaveLength(3);
});
