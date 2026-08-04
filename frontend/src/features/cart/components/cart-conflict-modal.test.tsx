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
    expect(screen.queryByText("Switch branch?")).not.toBeInTheDocument();
  });

  it("renders branch conflict dialog when open", () => {
    mockUseCart.mockReturnValue({
      conflict: {
        open: true,
        data: {
          current_cart: { branch_name: "Itahari Branch" },
          requested_branch: { branch_name: "Dharan Branch" },
        },
        pending: {},
      },
      clearConflict,
      confirmReplaceRestaurant,
    });
    render(<CartConflictModal />);
    expect(screen.getByText("Switch branch?")).toBeInTheDocument();
    expect(
      screen.getByText(
        /Your cart contains items from Itahari Branch\. To order from Dharan Branch, clear your current cart\./,
      ),
    ).toBeInTheDocument();
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

  it("calls confirmReplaceRestaurant on clear and switch", async () => {
    mockUseCart.mockReturnValue({
      conflict: { open: true, data: null, pending: { menu_item_public_id: "x", quantity: 1 } },
      clearConflict,
      confirmReplaceRestaurant,
    });
    render(<CartConflictModal />);
    fireEvent.click(screen.getAllByText("Clear cart and switch")[0]);
    await waitFor(() => expect(confirmReplaceRestaurant).toHaveBeenCalled());
  });
});
