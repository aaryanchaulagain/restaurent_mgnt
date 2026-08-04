/**
 * @vitest-environment node
 */
import { describe, expect, it } from "vitest";
import { readFileSync, existsSync } from "node:fs";
import { join } from "node:path";

describe("staging env safety", () => {
  it("staging example uses test publishable key and staging API only", () => {
    const path = join(process.cwd(), ".env.staging.example");
    expect(existsSync(path)).toBe(true);
    const text = readFileSync(path, "utf8");
    expect(text).toContain("NEXT_PUBLIC_API_URL=");
    expect(text).toMatch(/pk_test_/);
    expect(text).not.toMatch(/sk_live_|sk_test_|whsec_|PASSWORD=/i);
    expect(text).not.toMatch(/STRIPE_SECRET/i);
  });

  it("pins Node 22", () => {
    expect(readFileSync(join(process.cwd(), ".nvmrc"), "utf8").trim()).toBe("22");
  });
});
