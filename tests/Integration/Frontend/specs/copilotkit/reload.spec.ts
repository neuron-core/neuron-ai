import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/copilotkit";

test("browser reload: the runtime bridge restores the thread and the open interrupt", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "approval-title");
  await send(page, "What is the title?");
  const interrupt = page.getByTestId("interrupt");
  await expect(interrupt).toHaveAttribute("data-reason", "confirmation");

  await page.reload();
  await expect(page.getByTestId("copilot-user-message").or(page.locator(".copilot-user-message")).first()).toBeVisible();
  const restored = page.getByTestId("interrupt");
  await expect(restored).toHaveAttribute("data-reason", "confirmation");

  await restored.getByRole("button", { name: "Approve" }).click();
  await expectReply(page, 'Done: {"call_read_title_1":"Neuron Fixture"}');
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
});
