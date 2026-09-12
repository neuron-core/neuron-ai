import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { answer, openThread, run } from "../../support/agui";

test("structured results: objects, arrays, false, 0 and null cross the AG-UI boundary as their JSON text", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "structured", "Probe everything.");

  const first = await run(agent);
  expect(first.calls.map((call) => call.id)).toEqual(["call_object", "call_array", "call_false", "call_zero", "call_null"]);

  answer(agent, first.calls);
  const second = await run(agent);
  expect(second.reply).toMatch(/^Done: /);

  const audit = await observe(request, threadId);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({
    call_object: '{"a":1}',
    call_array: "[1,2]",
    call_false: "false",
    call_zero: "0",
    call_null: "null",
  });
});
