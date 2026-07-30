import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, afterEach } from "vitest";
import { useState } from "react";
import { PaymentMethodSelector } from "./PaymentMethodSelector";

function Harness() {
  const [method, setMethod] = useState<"cash" | "online_card">("cash");
  return <PaymentMethodSelector value={method} onChange={setMethod} />;
}

describe("PaymentMethodSelector", () => {
  afterEach(() => cleanup());

  it("switches between cash and online card", () => {
    render(<Harness />);
    fireEvent.click(screen.getByLabelText(/pay online with card/i));
    expect(screen.getByLabelText(/pay online with card/i)).toBeChecked();
  });

  it("disables online when onlineDisabled", () => {
    render(
      <PaymentMethodSelector
        value="cash"
        onChange={() => undefined}
        onlineDisabled
        onlineDisabledReason="Online payments unavailable"
      />,
    );
    expect(screen.getByLabelText(/pay online with card/i)).toBeDisabled();
    expect(screen.getByText(/Online payments unavailable/i)).toBeInTheDocument();
  });
});
