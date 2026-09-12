import { test, expect } from "@playwright/test";
import { FRONTEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";

test("one deferred tool: the useFrontendTool handler runs in the browser and its result reaches the next inference", async ({ page, request }) => {
  const threadId = await registerThread(request, "deferred-title");
  await page.goto(`${FRONTEND}/copilotkit/?thread=${encodeURIComponent(threadId)}&title=${encodeURIComponent("Neuron Fixture")}`);

  const input = page.getByTestId("copilot-chat-textarea");
  await input.fill("What is the title?");
  await input.press("Enter");

  const reply = page.getByTestId("copilot-assistant-message");
  await expect(reply.last()).toContainText('Done: {"call_read_title_1":"Neuron Fixture"}');
  expect(await page.evaluate(() => window.handlerRuns)).toEqual({ call_read_title_1: 1 });

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(audit.invocations.map((invocation) => invocation.tools)).toEqual([["read_title", "read_text", "probe"], ["read_title", "read_text", "probe"]]);
  expect(toolResultsSentToProvider(audit.invocations[0])).toEqual({});
  expect(Object.values(toolResultsSentToProvider(audit.invocations[1]))).toEqual(["Neuron Fixture"]);
  expect(audit.run).toBeNull();
});
