import { StrictMode, useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { useChat } from "@ai-sdk/react";
import { DefaultChatTransport, getToolName, isToolUIPart } from "ai";
import type { UIMessage } from "ai";
import { probe, titleFromParams } from "../shared";

declare global {
  interface Window {
    handlerRuns: Record<string, number>;
    releaseSlow: () => void;
  }
}

// Test controls arrive through the page URL, never through the chat request.
const params = new URLSearchParams(window.location.search);
const threadId = params.get("thread") ?? "unregistered";
document.title = titleFromParams(params);
// "eager" submits as soon as any tool has an output (partial batches); "slow" holds one
// selector's handler until the test calls window.releaseSlow().
const eagerSubmission = params.get("submit") === "eager";
const slowSelector = params.get("slow");
window.handlerRuns = {};
const slowRelease = new Promise<void>((resolve) => {
  window.releaseSlow = resolve;
});

// Frontend context survives a reload in this viewer's session storage.
const storageKey = `chat:${threadId}`;
function restoreMessages(): UIMessage[] {
  try {
    return JSON.parse(sessionStorage.getItem(storageKey) ?? "[]");
  } catch {
    return [];
  }
}

async function executeInBrowser(toolName: string, input: Record<string, unknown>): Promise<unknown> {
  switch (toolName) {
    case "read_title": return document.title;
    case "read_text":
      if (input.selector === slowSelector) await slowRelease;
      return document.querySelector(input.selector as string)?.textContent;
    case "probe": return probe(input.kind as string);
  }
  throw new Error(`No frontend handler for ${toolName}`);
}

function lastStepTools(messages: UIMessage[]) {
  const last = messages.at(-1);
  if (last?.role !== "assistant") return [];
  const step = last.parts.reduce((index, part, i) => (part.type === "step-start" ? i : index), -1);
  return last.parts.slice(step + 1).filter(isToolUIPart);
}

// Predicates must look at the last step only: after the final answer the earlier
// tool parts still exist, and an unscoped predicate would submit them again.
// An eager predicate must also remember what it already sent: a partial submission
// leaves the client state unchanged, so "any output available" alone would resubmit
// the same batch after every response until the pending call completes.
const submittedOutputs = new Set<string>();
function newOutputIsReady({ messages }: { messages: UIMessage[] }): boolean {
  const ready = lastStepTools(messages)
    .filter((part) => part.state === "output-available" || part.state === "output-error")
    .map((part) => part.toolCallId);
  const unsent = ready.filter((callId) => !submittedOutputs.has(callId));
  if (unsent.length === 0) return false;
  ready.forEach((callId) => submittedOutputs.add(callId));
  return true;
}

// Automatic continuation for mixed approval and tool-result batches; the SDK's
// individual helpers do not cover both in one step.
function lastStepIsSettled({ messages }: { messages: UIMessage[] }): boolean {
  const tools = lastStepTools(messages);
  if (!tools.length || tools.some((part) => part.state === "approval-requested")) return false;
  if (tools.some((part) => part.state === "approval-responded")) return true;
  return tools.every((part) => ["output-available", "output-error", "output-denied"].includes(part.state));
}

function App() {
  const [draft, setDraft] = useState("");
  const { messages, sendMessage, addToolOutput, addToolApprovalResponse, status, error } = useChat({
    id: threadId,
    messages: restoreMessages(),
    transport: new DefaultChatTransport({ api: "/api/vercel" }),
    sendAutomaticallyWhen: eagerSubmission ? newOutputIsReady : lastStepIsSettled,
    // useChat awaits onToolCall: start the work and return, so a slow tool never
    // blocks stream processing or the submission of the outputs already available.
    onToolCall({ toolCall }) {
      const { toolName, toolCallId } = toolCall;
      window.handlerRuns[toolCallId] = (window.handlerRuns[toolCallId] ?? 0) + 1;
      void executeInBrowser(toolName, toolCall.input as Record<string, unknown>)
        .then((output) => addToolOutput({ tool: toolName, toolCallId, output }))
        .catch((failure) => addToolOutput({ tool: toolName, toolCallId, state: "output-error", errorText: (failure as Error).message }));
    },
  });
  useEffect(() => {
    try {
      sessionStorage.setItem(storageKey, JSON.stringify(messages));
    } catch {}
  }, [messages]);

  return (
    <main>
      <p id="first">Alpha</p>
      <p id="second">Beta</p>
      <ol data-testid="messages">
        {messages.map((message) => (
          <li key={message.id} data-role={message.role} data-message-id={message.id}>
            {message.parts.map((part, index) => {
              if (part.type === "text") return <span key={index} data-part="text">{part.text}</span>;
              if (isToolUIPart(part)) {
                return (
                  <span key={index} data-part="tool" data-tool={getToolName(part)} data-call-id={part.toolCallId} data-state={part.state}>
                    {part.state === "approval-requested" && (
                      <>
                        <button onClick={() => addToolApprovalResponse({ id: part.approval.id, approved: true })}>Approve</button>
                        <button onClick={() => addToolApprovalResponse({ id: part.approval.id, approved: false, reason: "Not now" })}>Reject</button>
                      </>
                    )}
                    {part.state === "output-available" && <output>{JSON.stringify(part.output)}</output>}
                  </span>
                );
              }
              return <span key={index} data-part={part.type} />;
            })}
          </li>
        ))}
      </ol>
      <div data-testid="status">{status}</div>
      <div data-testid="error">{error?.message ?? ""}</div>
      <form
        onSubmit={(event) => {
          event.preventDefault();
          void sendMessage({ text: draft });
          setDraft("");
        }}
      >
        <input aria-label="Message" value={draft} onChange={(event) => setDraft(event.target.value)} />
        <button type="submit">Send</button>
      </form>
    </main>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
