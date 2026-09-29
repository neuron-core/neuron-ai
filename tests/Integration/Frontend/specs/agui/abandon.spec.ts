import { test, expect } from "@playwright/test";
import { LONG_STORY, LONG_STORY_ANSWER, observe } from "../../support/backend";
import { FRONTEND_TOOLS, openThread, run } from "../../support/agui";

test("a client that leaves mid-stream does not lock the thread: the next message runs at once", async ({ request }) => {
  const { threadId, agent } = await openThread(request, "abandoned-stream", LONG_STORY);

  // The client goes away after the first words of a five-second answer.
  let received = "";
  await agent.runAgent({ tools: FRONTEND_TOOLS }, {
    onTextMessageContentEvent: ({ event }) => {
      received += event.delta;
      agent.abortRun();
    },
  });
  expect(received).toMatch(/^Once upon a time\. /);
  expect(received.length).toBeLessThan(LONG_STORY_ANSWER.length);

  const left = await observe(request, threadId);
  expect(left.run?.status).toBe("failed");

  agent.addMessage({ id: "user-2", role: "user", content: "Are you still there?" });
  const next = await run(agent);
  expect(next.runError).toBeUndefined();
  expect(next.reply).toBe("Done: []");

  // The half-streamed answer never reached the history.
  const audit = await observe(request, threadId);
  expect(audit.history.map((message) => [message.role, message.content])).toEqual([
    ["user", [{ type: "text", content: "Are you still there?", meta: [] }]],
    ["assistant", [{ type: "text", content: "Done: []", meta: [] }]],
  ]);
});
