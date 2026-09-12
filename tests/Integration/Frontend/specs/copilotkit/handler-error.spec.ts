import { test, expect } from "@playwright/test";
import { observe } from "../../support/backend";
import { handlerRuns, openChat, send } from "../../support/copilotkit";

test("frontend handler throws: CopilotKit records the error locally and does not continue the run", async ({ page, request }) => {
  const { threadId, runs } = await openChat(page, request, "handler-error");
  await send(page, "Probe the failure.");

  await expect.poll(() => handlerRuns(page)).toEqual({ call_throw: 1 });
  await page.waitForTimeout(1500);

  // Client behaviour: a throwing handler produces a tool message "Error: ..." but no follow-up run.
  expect(runs).toHaveLength(1);
  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(1);
  expect(audit.run?.status).toBe("suspended");
});
