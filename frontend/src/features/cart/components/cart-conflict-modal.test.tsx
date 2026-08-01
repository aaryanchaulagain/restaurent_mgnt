import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { CartConflictModal } from "./cart-conflict-modal";

const mockUseCart = vi.fn();
vi.mock("./cart-provider", () => ({
  useCart: () => mockUseCart(),
}));

describe("CartConflictModal", () => {
  const clearConflict = vi.fn();
  const confirmReplaceRestaurant = vi.fn().mockResolvedValue(undefined);

  beforeEach(() => vi.clearAllMocks());

  it("does not render dialog content when conflict is not open", () => {
    mockUseCart.mockReturnValue({
      conflict: { open: false, data: null, pending: null },
      clearConflict,
      confirmReplaceRestaurant,
    });
    render(<CartConflictModal />);
    expect(screen.queryByText("Start a new order?")).not.toBeInTheDocument();
  });

  it("renders dialog when conflict is open", () => {
    mockUseCart.mockReturnValue({
      conflict: { open: true, data: null, pending: {} },
      clearConflict,
      confirmReplaceRestaurant,
    });
    render(<CartConflictModal />);
    expect(screen.getByText("Start a new order?")).toBeInTheDocument();
  });

  it("calls clearConflict when keeping current cart", () => {
    mockUseCart.mockReturnValue({
      conflict: { open: true, data: null, pending: {} },
      clearConflict,
      confirmReplaceRestaurant,
    });
    render(<CartConflictModal />);
    fireEvent.click(screen.getAllByText("Keep current cart")[0]);
    expect(clearConflict).toHaveBeenCalled();
  });

  it("calls confirmReplaceRestaurant on replace", async () => {
    mockUseCart.mockReturnValue({
      conflict: { open: true, data: null, pending: { menu_item_public_id: "x", quantity: 1 } },
      clearConflict,
      confirmReplaceRestaurant,
    });
    render(<CartConflictModal />);
    fireEvent.click(screen.getAllByText("Clear and start new order")[0]);
    await waitFor(() => expect(confirmReplaceRestaurant).toHaveBeenCalled());
  });
});
