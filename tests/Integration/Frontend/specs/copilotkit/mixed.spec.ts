import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/copilotkit";

test("mixed local and deferred tools: the browser executes only its own tool", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "mixed");
  await send(page, "Time and title?");

  await expectReply(page, 'Done: {"call_clock_1":"12:00","call_read_title_1":"Neuron Fixture"}');
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(audit.executions).toEqual([{ call_id: "call_clock_1", tool: "server_clock" }]);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_clock_1: "12:00", call_read_title_1: "Neuron Fixture" });
});
