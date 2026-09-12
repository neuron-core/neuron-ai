import { test, expect } from "@playwright/test";
import { LONG_TITLE } from "../../fixtures/shared";
import { openChat, send } from "../../support/vercel";

test("fragmented delivery: a long multi-byte result survives the UI message stream intact", async ({ page, request }) => {
  // document.title strips surrounding whitespace, so the browser reports the trimmed value.
  const title = LONG_TITLE.trim();
  await openChat(page, request, "deferred-title", { title: "__long__" });
  await send(page, "What is the title?");

  const reply = page.locator('[data-role="assistant"] [data-part="text"]').last();
  await expect(reply).toContainText("Done: ");
  expect(JSON.parse((await reply.textContent())!.slice("Done: ".length))).toEqual({ call_read_title_1: title });
  await expect(page.getByTestId("status")).toHaveText("ready");
});

test("an error before streaming starts surfaces as a chat error", async ({ page, request }) => {
  await openChat(page, request, "deferred-title", { thread: "never-registered" });
  await send(page, "Hello?");
  await expect(page.getByTestId("status")).toHaveText("error");
  await expect(page.getByTestId("error")).toContainText("was not registered");
});

test("an error after streaming started surfaces as a chat error with the protocol error text", async ({ page, request }) => {
  await openChat(page, request, "broken");
  await send(page, "Break.");
  await expect(page.getByTestId("status")).toHaveText("error");
  await expect(page.getByTestId("error")).toContainText("Unknown scenario 'broken'");
});
