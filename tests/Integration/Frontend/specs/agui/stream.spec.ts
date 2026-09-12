import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread } from "../../support/backend";
import { answer, run, setPageTitle } from "../../support/agui";

test("fragmented delivery: a long multi-byte result survives the SSE boundary intact", async ({ request }) => {
  const threadId = await registerThread(request, "deferred-title");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "What is the title?" });
  const first = await run(agent);

  const title = "Nëurón ✓ 🚀 ".repeat(4000);
  setPageTitle(title);
  try {
    answer(agent, first.calls);
  } finally {
    setPageTitle("Neuron Fixture");
  }
  const second = await run(agent);
  expect(second.reply?.startsWith("Done: ")).toBe(true);
  expect(JSON.parse(second.reply!.slice("Done: ".length))).toEqual({ call_read_title_1: title });
});

test("an error before streaming starts is an HTTP failure the client surfaces as a rejected run", async () => {
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId: "never-registered" });
  agent.addMessage({ id: "user-1", role: "user", content: "Hello?" });
  await expect(run(agent)).rejects.toThrow(/HTTP 500.*was not registered/);
});

test("an error after streaming started arrives as RUN_ERROR and the run is failed", async ({ request }) => {
  const threadId = await registerThread(request, "broken");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "Break." });

  // The official client resolves the run and reports RUN_ERROR through the subscriber.
  const failed = await run(agent);
  expect(failed.runError).toContain("Unknown scenario 'broken'");
  expect(failed.reply).toBeUndefined();

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(0);
  console.log("run record after a failed run:", JSON.stringify(audit.run));
});
