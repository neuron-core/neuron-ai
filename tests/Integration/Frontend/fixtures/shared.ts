/** Behaviour every frontend handler set shares, in the browser fixtures and the Node AG-UI client alike. */

/** A long multi-byte value that exercises fragmented SSE delivery. */
export const LONG_TITLE = "Nëurón ✓ 🚀 ".repeat(4000);

/** The page title under test arrives through the fixture URL; `__long__` stands for LONG_TITLE. */
export function titleFromParams(params: URLSearchParams): string {
  const title = params.get("title") ?? "Neuron Fixture";
  return title === "__long__" ? LONG_TITLE : title;
}

export function probe(kind: string): unknown {
  switch (kind) {
    case "object": return { a: 1 };
    case "array": return [1, 2];
    case "false": return false;
    case "zero": return 0;
    case "null": return null;
    default: throw new Error("probe failed");
  }
}
