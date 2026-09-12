import { test, expect } from "@playwright/test";
import type { Tool } from "@ag-ui/client";
import { observe } from "../../support/backend";
import { FRONTEND_TOOLS, answer, openThread, run } from "../../support/agui";

/** A declaration shaped the way frontend libraries generate them from typed schemas. */
const inspect: Tool = {
  name: "inspect",
  description: "Inspect part of the page.",
  parameters: {
    type: "object",
    properties: {
      target: {
        type: "object",
        description: "What to inspect",
        properties: {
          selector: { type: "string" },
          attributes: { type: "array", items: { type: "string" } },
        },
        required: ["selector"],
      },
      limit: { type: "integer", minimum: 1 },
      note: { type: ["string", "null"] },
    },
    required: ["target"],
  },
};

test("client-declared schemas with nested objects, arrays and nullable values are accepted and exposed to the model", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "deferred-title", "What is the title?");

  const first = await run(agent, undefined, [...FRONTEND_TOOLS, inspect]);
  answer(agent, first.calls);
  const second = await run(agent, undefined, [...FRONTEND_TOOLS, inspect]);
  expect(second.reply).toBe('Done: {"call_read_title_1":"Neuron Fixture"}');

  const audit = await observe(request, threadId);
  expect(audit.invocations[0].tools).toContain("inspect");
});

test("a tool the client no longer declares is not exposed, and the model asking for it fails the run", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "deferred-title", "What is the title?");

  const withoutReadTitle = FRONTEND_TOOLS.filter((tool) => tool.name !== "read_title");
  const failed = await run(agent, undefined, withoutReadTitle);
  expect(failed.runError).toContain("read_title");
  expect(failed.reply).toBeUndefined();

  const audit = await observe(request, threadId);
  expect(audit.invocations).toHaveLength(0);
});

test("a client declaration that shadows a backend tool is rejected by the application", async ({ request }) => {
  const { agent } = await openThread(request, "mixed", "Time and title?");
  const shadow: Tool = { name: "server_clock", description: "Pretend clock", parameters: { type: "object", properties: {} } };
  await expect(run(agent, undefined, [...FRONTEND_TOOLS, shadow])).rejects.toThrow(/HTTP 500.*collides/);
});
