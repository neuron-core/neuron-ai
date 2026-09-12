import { test, expect } from "@playwright/test";
import { observe } from "../../support/backend";
import { openChat, send } from "../../support/copilotkit";

test("fragmented delivery: a long multi-byte result survives the runtime bridge intact", async ({ page, request }) => {
  // document.title strips surrounding whitespace, so the browser reports the trimmed value.
  const title = "Nëurón ✓ 🚀 ".repeat(4000).trim();
  await openChat(page, request, "deferred-title", { title: "__long__" });
  await send(page, "What is the title?");

  const reply = page.getByTestId("copilot-assistant-message").last();
  await expect(reply).toContainText("Done: ");
  const text = (await reply.textContent())!;
  expect(JSON.parse(text.slice(text.indexOf("Done: ") + "Done: ".length))).toEqual({ call_read_title_1: title });
});

test("an error after streaming started fails the run without leaving a wait behind", async ({ page, request }) => {
  const { threadId } = await openChat(page, request, "broken");
  await send(page, "Break.");
  await expect.poll(async () => (await observe(request, threadId)).run?.status, { timeout: 15_000 }).toBe("failed");
  expect((await observe(request, threadId)).invocations).toHaveLength(0);
});
