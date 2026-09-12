import { HttpAgent } from "@ag-ui/client";
import type { Interrupt, ResumeEntry, Tool } from "@ag-ui/client";

/** The catalog a frontend declares; mirrors the fixture's own declarations for Vercel. */
export const FRONTEND_TOOLS: Tool[] = [
  { name: "read_title", description: "Read the title of the page the user is looking at.", parameters: { type: "object", properties: {} } },
  {
    name: "read_text",
    description: "Read the text of an element on the page.",
    parameters: { type: "object", properties: { selector: { type: "string", description: "CSS selector" } }, required: ["selector"] },
  },
  {
    name: "probe",
    description: "Return a probe value of the requested kind.",
    parameters: { type: "object", properties: { kind: { type: "string", enum: ["object", "array", "false", "zero", "null", "throw"] } }, required: ["kind"] },
  },
];

export interface ObservedCall {
  id: string;
  name: string;
  args: Record<string, unknown>;
}

export interface RunObservation {
  calls: ObservedCall[];
  interrupts: Interrupt[];
  runError?: string;
  /** Raw TOOL_CALL_RESULT frames as the client parsed them, by call id. */
  resultFrames: Record<string, Record<string, unknown>>;
  reply?: string;
}

/** Run the official client once and observe what it surfaced to the application. */
export async function run(agent: HttpAgent, resume?: ResumeEntry[], tools: Tool[] = FRONTEND_TOOLS): Promise<RunObservation> {
  const observation: RunObservation = { calls: [], interrupts: [], resultFrames: {} };
  const result = await agent.runAgent({ tools, ...(resume ? { resume } : {}) }, {
    onToolCallEndEvent: ({ event, toolCallName, toolCallArgs }) => {
      observation.calls.push({ id: event.toolCallId, name: toolCallName, args: toolCallArgs });
    },
    onToolCallResultEvent: ({ event }) => {
      observation.resultFrames[event.toolCallId] = event as unknown as Record<string, unknown>;
    },
    onRunErrorEvent: ({ event }) => {
      observation.runError = event.message;
    },
    onRunFinishedEvent: (params) => {
      if (params.outcome === "interrupt") observation.interrupts = params.interrupts;
    },
  });
  const reply = result.newMessages.find((message) => message.role === "assistant" && typeof message.content === "string" && message.content !== "");
  if (reply) observation.reply = reply.content as string;
  return observation;
}

export let pageTitle = "Neuron Fixture";

export function setPageTitle(title: string): void {
  pageTitle = title;
}

/** What the browser side would have produced for a call, executed here in Node. */
export function execute(call: ObservedCall): unknown {
  switch (call.name) {
    case "read_title":
      return pageTitle;
    case "read_text":
      return { "#first": "Alpha", "#second": "Beta" }[call.args.selector as string];
    case "probe":
      switch (call.args.kind) {
        case "object": return { a: 1 };
        case "array": return [1, 2];
        case "false": return false;
        case "zero": return 0;
        case "null": return null;
        default: throw new Error("probe failed");
      }
  }
  throw new Error(`No frontend handler for ${call.name}`);
}

/**
 * A frontend executes only the calls it has handlers for and that carry no result
 * yet; backend-executed and rejected calls already arrived with theirs.
 */
export function pending(agent: HttpAgent, calls: ObservedCall[]): ObservedCall[] {
  const answered = new Set(agent.messages.filter((message) => message.role === "tool").map((message) => message.toolCallId));
  const owned = new Set(FRONTEND_TOOLS.map((tool) => tool.name));
  return calls.filter((call) => owned.has(call.name) && !answered.has(call.id));
}

/** Hand results back the way an AG-UI frontend does: one tool message per executed call, content as text. */
export function answer(agent: HttpAgent, calls: ObservedCall[]): ObservedCall[] {
  const executed = pending(agent, calls);
  for (const call of executed) {
    try {
      const value = execute(call);
      agent.addMessage({ id: `result-${call.id}`, role: "tool", toolCallId: call.id, content: typeof value === "string" ? value : JSON.stringify(value) });
    } catch (error) {
      agent.addMessage({ id: `result-${call.id}`, role: "tool", toolCallId: call.id, content: "", error: (error as Error).message });
    }
  }
  return executed;
}
