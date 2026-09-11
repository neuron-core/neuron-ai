import { test, expect } from "@playwright/test";
import { HttpAgent } from "@ag-ui/client";
import { BACKEND, observe, registerThread, toolResultsSentToProvider } from "../../support/backend";

const readTitle = {
  name: "read_title",
  description: "Read the title of the page the user is looking at.",
  parameters: { type: "object", properties: {} },
};

test("one deferred tool: the official client receives the call, returns its result, and sees the continuation", async ({ request }) => {
  const threadId = await registerThread(request, "deferred-title");
  const agent = new HttpAgent({ url: `${BACKEND}/agui`, threadId });
  agent.addMessage({ id: "user-1", role: "user", content: "What is the title?" });

  const calls: Array<{ id: string; name: string; args: Record<string, unknown> }> = [];
  const first = await agent.runAgent({ tools: [readTitle] }, {
    onToolCallEndEvent: ({ event, toolCallName, toolCallArgs }) => {
      calls.push({ id: event.toolCallId, name: toolCallName, args: toolCallArgs });
    },
  });

  expect(calls).toEqual([{ id: expect.any(String), name: "read_title", args: {} }]);
  const call = calls[0];
  const assistant = first.newMessages.find((message) => message.role === "assistant");
  expect(assistant && "toolCalls" in assistant ? assistant.toolCalls?.map((c) => c.id) : []).toEqual([call.id]);
  expect(agent.messages.map((message) => message.role)).toEqual(["user", "assistant"]);

  // The frontend "executes" the tool and hands the outcome back as a tool message.
  agent.addMessage({ id: "result-1", role: "tool", toolCallId: call.id, content: "Neuron Fixture" });
  const second = await agent.runAgent({ tools: [readTitle] });

  const reply = second.newMessages.find((message) => message.role === "assistant");
  expect(reply?.content).toBe("The page title is: Neuron Fixture");
  expect(agent.messages.map((message) => message.role)).toEqual(["user", "assistant", "tool", "assistant"]);

  const audit = await observe(request, threadId);
  expect(audit.invocations.map((invocation) => invocation.tools)).toEqual([["read_title"], ["read_title"]]);
  expect(toolResultsSentToProvider(audit.invocations[0])).toEqual({});
  expect(toolResultsSentToProvider(audit.invocations[1])).toEqual({ [call.id]: "Neuron Fixture" });
  expect(audit.run).toBeNull();
});
