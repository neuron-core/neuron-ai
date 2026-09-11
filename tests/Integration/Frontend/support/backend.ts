import { randomUUID } from "node:crypto";
import type { APIRequestContext } from "@playwright/test";

export const BACKEND = "http://127.0.0.1:8787";
export const FRONTEND = "http://127.0.0.1:5173";

export interface ProviderInvocation {
  method: string;
  messages: Array<Record<string, any>>;
  tools: string[];
  response: Record<string, any>;
}

export interface ThreadAudit {
  run: { runId: string; status: string; executionAttempt: number; interrupts: string[] } | null;
  invocations: ProviderInvocation[];
  history: Array<{ role: string; content: unknown; meta: Record<string, any> | null }>;
}

/** Bind a fresh thread to a backend scenario; the id is the only thing the client learns. */
export async function registerThread(request: APIRequestContext, scenario: string): Promise<string> {
  const threadId = `${scenario}-${randomUUID()}`;
  const response = await request.post(`${BACKEND}/_test/threads`, { data: { threadId, scenario } });
  if (!response.ok()) throw new Error(`Thread registration failed: ${await response.text()}`);
  return threadId;
}

export async function observe(request: APIRequestContext, threadId: string): Promise<ThreadAudit> {
  const response = await request.get(`${BACKEND}/_test/threads/${encodeURIComponent(threadId)}`);
  if (!response.ok()) throw new Error(`Observation failed: ${await response.text()}`);
  return response.json();
}

/** The tool results Neuron passed to the provider on a given invocation, keyed by call id. */
export function toolResultsSentToProvider(invocation: ProviderInvocation): Record<string, unknown> {
  const results: Record<string, unknown> = {};
  for (const message of invocation.messages) {
    if (message.type !== "tool_call_result") continue;
    for (const call of message.tools) results[call.callId] = call.result;
  }
  return results;
}
