import { StrictMode, useState } from "react";
import { createRoot } from "react-dom/client";
import { useChat } from "@ai-sdk/react";
import { DefaultChatTransport, getToolName, isToolUIPart, lastAssistantMessageIsCompleteWithToolCalls } from "ai";

declare global {
  interface Window {
    handlerRuns: Record<string, number>;
  }
}

// Test controls arrive through the page URL, never through the chat request.
const params = new URLSearchParams(window.location.search);
const threadId = params.get("thread") ?? "unregistered";
document.title = params.get("title") ?? "Neuron Fixture";
window.handlerRuns = {};

function App() {
  const [draft, setDraft] = useState("");
  const { messages, sendMessage, addToolOutput, status, error } = useChat({
    id: threadId,
    transport: new DefaultChatTransport({ api: "/api/vercel" }),
    sendAutomaticallyWhen: lastAssistantMessageIsCompleteWithToolCalls,
    onToolCall({ toolCall }) {
      if (toolCall.toolName !== "read_title") return;
      window.handlerRuns[toolCall.toolCallId] = (window.handlerRuns[toolCall.toolCallId] ?? 0) + 1;
      addToolOutput({ tool: "read_title", toolCallId: toolCall.toolCallId, output: document.title });
    },
  });

  return (
    <main>
      <ol data-testid="messages">
        {messages.map((message) => (
          <li key={message.id} data-role={message.role} data-message-id={message.id}>
            {message.parts.map((part, index) => {
              if (part.type === "text") return <span key={index} data-part="text">{part.text}</span>;
              if (isToolUIPart(part)) {
                return <span key={index} data-part="tool" data-tool={getToolName(part)} data-call-id={part.toolCallId} data-state={part.state} />;
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
