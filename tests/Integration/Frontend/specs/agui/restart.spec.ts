import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { observe, startBackend, toolResultsSentToProvider } from "../../support/backend";
import { answer, openThread, run } from "../../support/agui";

test("backend restart: a fresh process continues the persisted wait and completed backend work is not rerun", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "mixed", "Time and title?");
  const first = await run(agent);
  expect(answer(agent, first.calls).map((call) => call.id)).toEqual(["call_read_title_1"]);

  const restarted = await startBackend(8788);
  try {
    const continued = new HttpAgent({ url: `${restarted.url}/agui`, threadId, initialMessages: agent.messages });
    const second = await run(continued);
    expect(second.reply).toBe('Done: {"call_clock_1":"12:00","call_read_title_1":"Neuron Fixture"}');
  } finally {
    restarted.stop();
  }

  const audit = await observe(request, threadId);
  expect(audit.executions).toEqual([{ call_id: "call_clock_1", tool: "server_clock" }]);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_clock_1: "12:00", call_read_title_1: "Neuron Fixture" });
  expect(audit.run).toBeNull();
});
