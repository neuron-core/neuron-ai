import { test, expect } from "@playwright/test";
import { FRONTEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";

test("one deferred tool: the useChat handler runs in the browser and its result reaches the next inference", async ({ page, request }) => {
  const threadId = await registerThread(request, "deferred-title");
  await page.goto(`${FRONTEND}/vercel/?thread=${encodeURIComponent(threadId)}&title=${encodeURIComponent("Neuron Fixture")}`);

  await page.getByLabel("Message").fill("What is the title?");
  await page.getByRole("button", { name: "Send" }).click();

  const reply = page.locator('[data-role="assistant"] [data-part="text"]');
  await expect(reply).toHaveText('Done: {"call_read_title_1":"Neuron Fixture"}');
  await expect(page.getByTestId("status")).toHaveText("ready");
  await expect(page.getByTestId("error")).toHaveText("");

  const toolPart = page.locator('[data-part="tool"][data-tool="read_title"]');
  await expect(toolPart).toHaveCount(1);
  await expect(toolPart).toHaveAttribute("data-state", "output-available");
  const callId = await toolPart.getAttribute("data-call-id");
  expect(await page.evaluate(() => window.handlerRuns)).toEqual({ [callId!]: 1 });

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[0])).toEqual({});
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ [callId!]: "Neuron Fixture" });
  expect(audit.run).toBeNull();
});
