import { test, expect } from "@playwright/test";
import { observe, toolResultsSentToProvider } from "../../support/backend";
import { answer, openThread, run } from "../../support/agui";

test("continued conversation: a second inference step dispatches only its new call and completed work is not repeated", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "two-steps", "Title, then first paragraph.");

  const first = await run(agent);
  expect(first.calls.map((call) => call.id)).toEqual(["call_read_title_1"]);

  answer(agent, first.calls);
  const second = await run(agent);
  expect(second.calls.map((call) => call.id)).toEqual(["call_text_1"]);
  expect(second.reply).toBeUndefined();

  answer(agent, second.calls);
  const third = await run(agent);
  expect(third.reply).toBe('Done: {"call_read_title_1":"Neuron Fixture","call_text_1":"Alpha"}');

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(3);
  expect(toolResultsSentToProvider(audit.invocations[2])).toEqual({ call_read_title_1: "Neuron Fixture", call_text_1: "Alpha" });
  expect(agent.messages.filter((message) => message.role === "tool")).toHaveLength(2);
});
