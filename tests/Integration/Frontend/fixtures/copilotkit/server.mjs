// CopilotKit's documented runtime bridge: its React hooks talk to this runtime,
// which drives the Neuron AG-UI endpoint through the official HttpAgent.
import { createServer } from "node:http";
import { HttpAgent } from "@ag-ui/client";
import { CopilotRuntime, createCopilotRuntimeHandler } from "@copilotkit/runtime/v2";
import { createCopilotNodeHandler } from "@copilotkit/runtime/v2/node";

const BACKEND = process.env.NEURON_BACKEND ?? "http://127.0.0.1:8787";
const PORT = Number(process.env.PORT ?? 4000);

const handler = createCopilotNodeHandler(
  createCopilotRuntimeHandler({
    runtime: new CopilotRuntime({ agents: { default: new HttpAgent({ url: `${BACKEND}/agui` }) } }),
    basePath: "/runtime",
  }),
);

createServer((request, response) => {
  if (request.url === "/health") {
    response.writeHead(200, { "content-type": "application/json" }).end('{"ok":true}');
    return;
  }
  void handler(request, response);
}).listen(PORT, "127.0.0.1", () => console.log(`CopilotKit runtime bridge on http://127.0.0.1:${PORT}`));
