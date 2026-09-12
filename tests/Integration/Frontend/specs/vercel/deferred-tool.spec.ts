import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send, toolPart } from "../../support/vercel";

test("one deferred tool: the useChat handler runs in the browser and its result reaches the next inference", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "deferred-title");
  await send(page, "What is the title?");

  await expectReply(page, 'Done: {"call_read_title_1":"Neuron Fixture"}');
  await expect(toolPart(page, "call_read_title_1")).toHaveAttribute("data-state", "output-available");
  expect(await handlerRuns(page)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[0])).toEqual({});
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_read_title_1: "Neuron Fixture" });
  expect(audit.run).toBeNull();
});
