import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe } from "../../support/backend";
import { answer, openThread, run } from "../../support/agui";
import { LONG_TITLE } from "../../fixtures/shared";

test("fragmented delivery: a long multi-byte result survives the SSE boundary intact", async ({ request }) => {
  const { agent } = await openThread(request, "deferred-title", "What is the title?");
  const first = await run(agent);
  answer(agent, first.calls, { read_title: LONG_TITLE });
  const second = await run(agent);
  expect(second.reply?.startsWith("Done: ")).toBe(true);
  expect(JSON.parse(second.reply!.slice("Done: ".length))).toEqual({ call_read_title_1: LONG_TITLE });
});

test("an error before streaming starts is an HTTP failure the client surfaces as a rejected run", async () => {
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId: "never-registered" });
  agent.addMessage({ id: "user-1", role: "user", content: "Hello?" });
  await expect(run(agent)).rejects.toThrow(/HTTP 500.*was not registered/);
});

test("an error after streaming started arrives as RUN_ERROR and the run is failed", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "broken", "Break.");

  // The official client resolves the run and reports RUN_ERROR through the subscriber.
  const failed = await run(agent);
  expect(failed.runError).toContain("Unknown scenario 'broken'");
  expect(failed.reply).toBeUndefined();

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(0);
});
