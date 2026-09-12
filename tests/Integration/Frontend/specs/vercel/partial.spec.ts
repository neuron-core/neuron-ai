import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send, toolPart } from "../../support/vercel";

test("partial submission: an eager client sends the first output alone, the pending call stays visible and nothing repeats", async ({ page, request }) => {
  const { threadId, requests } = await openChat(page, request, "same-name", { submit: "eager", slow: "#second" });
  const partialAnswered = page.waitForResponse((response) =>
    response.url().endsWith("/api/vercel") && (response.request().postData() ?? "").includes("output-available"));
  await send(page, "Read both.");

  await partialAnswered;
  await expect(toolPart(page, "call_text_2")).toHaveAttribute("data-state", "input-available");
  expect((await observe(request, threadId)).run?.status).toBe("suspended");
  await page.evaluate(() => window.releaseSlow());

  await expectReply(page, 'Done: {"call_text_1":"Alpha","call_text_2":"Beta"}');
  expect(await handlerRuns(page)).toEqual({ call_text_1: 1, call_text_2: 1 });
  await expect(toolPart(page, "call_text_1")).toHaveCount(1);
  await expect(toolPart(page, "call_text_2")).toHaveCount(1);

  // Turn, first output alone, then the second output.
  expect(requests).toHaveLength(3);
  const partial = JSON.parse(requests[1]).messages.at(-1).parts.filter((part: { type: string }) => part.type === "tool-read_text");
  expect(partial.map((part: { state: string }) => part.state).sort()).toEqual(["input-available", "output-available"]);

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_text_1: "Alpha", call_text_2: "Beta" });
  expect(audit.run).toBeNull();
});
