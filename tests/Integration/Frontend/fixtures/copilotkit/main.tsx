import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { CopilotKit, CopilotChat, useFrontendTool } from "@copilotkit/react-core/v2";
import { z } from "zod";
import "@copilotkit/react-core/v2/styles.css";

declare global {
  interface Window {
    handlerRuns: Record<string, number>;
  }
}

// Test controls arrive through the page URL, never through the protocol request.
const params = new URLSearchParams(window.location.search);
const threadId = params.get("thread") ?? "unregistered";
document.title = params.get("title") ?? "Neuron Fixture";
window.handlerRuns = {};

function ReadTitleTool() {
  useFrontendTool({
    name: "read_title",
    description: "Read the title of the page the user is looking at.",
    parameters: z.object({}),
    handler: async () => {
      window.handlerRuns.read_title = (window.handlerRuns.read_title ?? 0) + 1;
      return document.title;
    },
  });
  return null;
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <CopilotKit runtimeUrl="/copilotkit">
      <ReadTitleTool />
      <CopilotChat threadId={threadId} />
    </CopilotKit>
  </StrictMode>,
);
