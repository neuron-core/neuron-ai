import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { FRONTEND, registerThread } from "./backend";

/** Open the CopilotKit fixture on a fresh thread and count every agent run the client starts. */
export async function openChat(page: Page, request: APIRequestContext, scenario: string, options: Record<string, string> = {}) {
  const threadId = await registerThread(request, scenario);
  const runs: string[] = [];
  page.on("request", (sent) => {
    if (sent.method() === "POST" && sent.url().endsWith("/runtime/agent/default/run")) runs.push(sent.postData() ?? "");
  });
  const query = new URLSearchParams({ thread: threadId, title: "Neuron Fixture", ...options });
  await page.goto(`${FRONTEND}/copilotkit/?${query}`);
  return { threadId, runs };
}

export async function send(page: Page, text: string): Promise<void> {
  const input = page.getByTestId("copilot-chat-textarea");
  await input.fill(text);
  await input.press("Enter");
}

export async function expectReply(page: Page, text: string): Promise<void> {
  await expect(page.getByTestId("copilot-assistant-message").last()).toContainText(text);
}

export function handlerRuns(page: Page): Promise<Record<string, number>> {
  return page.evaluate(() => window.handlerRuns);
}
