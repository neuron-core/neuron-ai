import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/copilotkit";

test("one deferred tool: the useFrontendTool handler runs in the browser and its result reaches the next inference", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "deferred-title");
  await send(page, "What is the title?");

  await expectReply(page, 'Done: {"call_read_title_1":"Neuron Fixture"}');
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(audit.invocations.map((invocation) => invocation.tools)).toEqual([["read_title", "read_text", "probe"], ["read_title", "read_text", "probe"]]);
  expect(toolResultsSentToProvider(audit.invocations[0])).toEqual({});
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
  expect(audit.run).toBeNull();
});
