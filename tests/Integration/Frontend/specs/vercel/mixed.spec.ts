import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send, toolPart } from "../../support/vercel";

test("mixed local and deferred tools: the browser executes only its own tool and sees the backend result", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "mixed");
  await send(page, "Time and title?");

  await expectReply(page, 'Done: {"call_clock_1":"12:00","call_read_title_1":"Neuron Fixture"}');
  await expect(toolPart(page, "call_clock_1")).toHaveAttribute("data-state", "output-available");
  await expect(toolPart(page, "call_clock_1").locator("output")).toHaveText('"12:00"');
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(audit.executions).toEqual([{ call_id: "call_clock_1", tool: "server_clock" }]);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_clock_1: "12:00", call_read_title_1: "Neuron Fixture" });
});
