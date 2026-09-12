import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { CopilotKit, CopilotChat, useFrontendTool, useInterrupt } from "@copilotkit/react-core/v2";
import { z } from "zod";
import { probe, titleFromParams } from "../shared";
import "@copilotkit/react-core/v2/styles.css";

declare global {
  interface Window {
    handlerRuns: Record<string, number>;
  }
}

// Test controls arrive through the page URL, never through the protocol request.
const params = new URLSearchParams(window.location.search);
const threadId = params.get("thread") ?? "unregistered";
document.title = titleFromParams(params);
// Which tool components are mounted; registration follows the component lifecycle.
const mounted = new Set((params.get("tools") ?? "read_title,read_text,probe").split(","));
window.handlerRuns = {};

function count(callId: string): void {
  window.handlerRuns[callId] = (window.handlerRuns[callId] ?? 0) + 1;
}

function ReadTitleTool() {
  useFrontendTool({
    name: "read_title",
    description: "Read the title of the page the user is looking at.",
    parameters: z.object({}),
    handler: async (_args, context) => {
      count(context.toolCall.id);
      return document.title;
    },
  });
  return null;
}

function InspectTool() {
  useFrontendTool({
    name: "inspect",
    description: "Inspect part of the page.",
    parameters: z.object({
      target: z.object({ selector: z.string(), attributes: z.array(z.string()).optional() }).describe("What to inspect"),
      limit: z.number().int().min(1).optional(),
      note: z.string().nullable().optional(),
    }),
    handler: async ({ target }) => document.querySelector(target.selector)?.outerHTML ?? "",
  });
  return null;
}

function OtherTools() {
  useFrontendTool({
    name: "read_text",
    description: "Read the text of an element on the page.",
    parameters: z.object({ selector: z.string().describe("CSS selector") }),
    handler: async ({ selector }, context) => {
      count(context.toolCall.id);
      return document.querySelector(selector)?.textContent ?? "";
    },
  });
  useFrontendTool({
    name: "probe",
    description: "Return a probe value of the requested kind.",
    parameters: z.object({ kind: z.enum(["object", "array", "false", "zero", "null", "throw"]) }),
    handler: async ({ kind }, context) => {
      count(context.toolCall.id);
      return probe(kind);
    },
  });
  return null;
}

function Approvals() {
  useInterrupt({
    render: ({ interrupt, resolve }) => (
      <div data-testid="interrupt" data-interrupt-id={interrupt?.id} data-reason={interrupt?.reason}>
        <button onClick={() => resolve({ approved: true })}>Approve</button>
        <button onClick={() => resolve({ approved: false, reason: "Not now" })}>Reject</button>
      </div>
    ),
  });
  return null;
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <p id="first">Alpha</p>
    <p id="second">Beta</p>
    <CopilotKit runtimeUrl="/runtime">
      {mounted.has("read_title") && <ReadTitleTool />}
      {mounted.has("inspect") && <InspectTool />}
      <OtherTools />
      <Approvals />
      <CopilotChat threadId={threadId} />
    </CopilotKit>
  </StrictMode>,
);
