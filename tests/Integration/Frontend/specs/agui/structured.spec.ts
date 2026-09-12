import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";
import { answer, run } from "../../support/agui";

test("structured results: objects, arrays, false, 0 and null cross the AG-UI boundary as their JSON text", async ({ request }) => {
  const threadId = await registerThread(request, "structured");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "Probe everything." });

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
