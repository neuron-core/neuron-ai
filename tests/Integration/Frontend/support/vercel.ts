import { expect, type APIRequestContext, type Page } from "@playwright/test";
import { FRONTEND, registerThread } from "./backend";
export { handlerRuns } from "./backend";

/** Open the useChat fixture on a fresh thread and count every chat request it sends. */
export async function openChat(page: Page, request: APIRequestContext, scenario: string, options: Record<string, string> = {}) {
  const threadId = options.thread ?? (await registerThread(request, scenario));
  const requests: string[] = [];
  page.on("request", (sent) => {
    if (sent.method() === "POST" && sent.url().endsWith("/api/vercel")) requests.push(sent.postData() ?? "");
  });
  const query = new URLSearchParams({ thread: threadId, title: "Neuron Fixture", ...options });
  await page.goto(`${FRONTEND}/vercel/?${query}`);
  return { threadId, requests };
}

export async function send(page: Page, text: string): Promise<void> {
  await page.getByLabel("Message").fill(text);
  await page.getByRole("button", { name: "Send" }).click();
}

export async function expectReply(page: Page, text: string): Promise<void> {
  await expect(page.locator('[data-role="assistant"] [data-part="text"]').last()).toHaveText(text);
  await expect(page.getByTestId("status")).toHaveText("ready");
  await expect(page.getByTestId("error")).toHaveText("");
}

export function toolPart(page: Page, callId: string) {
  return page.locator(`[data-part="tool"][data-call-id="${callId}"]`);
}
