/**
 * @vitest-environment node
 */
import { describe, expect, it } from "vitest";
import { readFileSync, existsSync } from "node:fs";
import { join } from "node:path";

describe("production env safety", () => {
  it("frontend .env.example does not expose server secrets via NEXT_PUBLIC", () => {
    const path = join(process.cwd(), ".env.example");
    expect(existsSync(path)).toBe(true);
    const text = readFileSync(path, "utf8");
    const publicLines = text
      .split(/\r?\n/)
      .filter((l) => l.startsWith("NEXT_PUBLIC_") && !l.trimStart().startsWith("#"));

    for (const line of publicLines) {
      expect(line).not.toMatch(/SECRET|PASSWORD|PRIVATE_KEY|sk_live|whsec_/i);
      expect(line).not.toMatch(/STRIPE_SECRET/i);
    }
  });

  it("documents required public API URL", () => {
    const text = readFileSync(join(process.cwd(), ".env.example"), "utf8");
    expect(text).toContain("NEXT_PUBLIC_API_URL=");
  });
});
