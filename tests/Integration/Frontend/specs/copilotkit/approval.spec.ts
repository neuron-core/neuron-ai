import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/copilotkit";

test("approval followed by execution: the interrupt is rendered, the handler runs once after approval", async ({ page, request }) => {
  const { threadId, runs } = await openChat(page, request, "approval-title");
  await send(page, "What is the title?");

  const interrupt = page.getByTestId("interrupt");
  await expect(interrupt).toHaveAttribute("data-reason", "confirmation");
  expect(await handlerRuns(page)).toEqual({});
  expect((await observe(request, threadId)).invocations).toHaveLength(1);

  await interrupt.getByRole("button", { name: "Approve" }).click();
  await expectReply(page, 'Done: {"call_read_title_1":"Neuron Fixture"}');
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
  expect(audit.run).toBeNull();
  expect(runs).toHaveLength(3);
});

test("approval rejection: the handler never runs and inference continues with the rejection", async ({ page, request }) => {
  const { threadId, runs } = await openChat(page, request, "approval-title");
  await send(page, "What is the title?");

  const interrupt = page.getByTestId("interrupt");
  await expect(interrupt).toHaveAttribute("data-reason", "confirmation");
  await interrupt.getByRole("button", { name: "Reject" }).click();

  await expectReply(page, "Done: ");
  expect(await handlerRuns(page)).toEqual({});

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(JSON.stringify(toolResultsSentToProvider(audit.invocations[1]).call_read_title_1)).toContain("Not now");
  expect(audit.run).toBeNull();
  expect(runs).toHaveLength(2);
});
