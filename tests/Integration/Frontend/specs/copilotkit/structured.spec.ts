import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { expectReply, handlerRuns, openChat, send } from "../../support/copilotkit";

test("structured results: CopilotKit serializes handler values to text before they reach Neuron", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "structured");
  await send(page, "Probe everything.");

  await expectReply(page, "Done: ");
  expect(Object.keys(await handlerRuns(page))).toHaveLength(5);

  const audit = await observe(request, threadId);
  const results = toolResultsSentToProvider(audit.invocations[1]);
  console.log("structured results as received by the provider:", JSON.stringify(results));
  // CopilotKit's documented normalization: objects and arrays as JSON, false and 0 as
  // their text, and null/undefined as an empty string.
  expect(results).toEqual({ call_object: '{"a":1}', call_array: "[1,2]", call_false: "false", call_zero: "0", call_null: "" });
});
