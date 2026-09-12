import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { handlerRuns, openChat, send, toolPart } from "../../support/vercel";

test("structured results: objects, arrays, false, 0 and null survive the Vercel boundary", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "structured");
  await send(page, "Probe everything.");

  await expect(page.locator('[data-role="assistant"] [data-part="text"]').last()).toContainText("Done: ");
  for (const [callId, output] of [["call_object", '{"a":1}'], ["call_array", "[1,2]"], ["call_false", "false"], ["call_zero", "0"], ["call_null", "null"]]) {
    await expect(toolPart(page, callId).locator("output")).toHaveText(output);
  }
  expect(Object.keys(await handlerRuns(page))).toHaveLength(5);

  const audit = await observe(request, threadId);
  console.log("structured results as received by the provider:", JSON.stringify(toolResultsSentToProvider(audit.invocations[1])));
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({
    call_object: '{"a":1}',
    call_array: "[1,2]",
    call_false: "false",
    call_zero: "0",
    call_null: "null",
  });
});
