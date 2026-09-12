import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";
import { answer, run } from "../../support/agui";

test("mixed local and deferred tools: local work runs once on the backend and only the deferred call reaches the client", async ({ request }) => {
  const threadId = await registerThread(request, "mixed");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "Time and title?" });

  const first = await run(agent);
  expect(first.calls.map((call) => call.name)).toEqual(["server_clock", "read_title"]);
  const clockResult = agent.messages.find((message) => message.role === "tool" && message.toolCallId === "call_clock_1");
  expect(clockResult?.content).toBe("12:00");

  expect(answer(agent, first.calls).map((call) => call.id)).toEqual(["call_read_title_1"]);
  const second = await run(agent);
  expect(second.reply).toBe('Done: {"call_clock_1":"12:00","call_read_title_1":"Neuron Fixture"}');

  const audit = await observe(request, threadId);
  expect(audit.executions).toEqual([{ call_id: "call_clock_1", tool: "server_clock" }]);
  expect(audit.invocations).toHaveLength(2);
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ call_clock_1: "12:00", call_read_title_1: "Neuron Fixture" });
});
