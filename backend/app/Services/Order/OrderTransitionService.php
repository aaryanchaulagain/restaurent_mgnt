<?php

namespace App\Services\Order;

use App\Exceptions\OrderApiException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Inventory\InventoryReservationService;
use App\Support\OrderErrorResponse;
use Illuminate\Support\Facades\DB;

class OrderTransitionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrderEventDispatcher $events,
        private readonly InventoryReservationService $reservations,
    ) {}

    public function transition(Order $order, string $newStatus, ?User $actor, string $actorType, ?string $reason = null, ?array $extra = []): Order
    {
        return DB::transaction(function () use ($order, $newStatus, $actor, $actorType, $reason, $extra) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $oldStatus = $order->status;

            if ($oldStatus === $newStatus) {
                if ($newStatus === 'accepted') {
                    throw new OrderApiException('ORDER_ALREADY_ACCEPTED', OrderErrorResponse::messageForCode('ORDER_ALREADY_ACCEPTED'), 409);
                }
                if ($newStatus === 'rejected') {
                    throw new OrderApiException('ORDER_ALREADY_REJECTED', OrderErrorResponse::messageForCode('ORDER_ALREADY_REJECTED'), 409);
                }

                return $order;
            }

            if ($newStatus === 'accepted' && $oldStatus === 'pending_payment') {
                throw new OrderApiException('INVALID_ORDER_TRANSITION', OrderErrorResponse::messageForCode('INVALID_ORDER_TRANSITION'), 409);
            }

            if (! OrderStatusMachine::canTransition($oldStatus, $newStatus)) {
                throw new OrderApiException('INVALID_ORDER_TRANSITION', OrderErrorResponse::messageForCode('INVALID_ORDER_TRANSITION'), 409);
            }

            $timestamps = match ($newStatus) {
                'accepted' => ['accepted_at' => now(), 'accepted_by' => $actor?->id, 'estimated_ready_at' => $extra['estimated_ready_at'] ?? null],
                'rejected' => ['rejected_at' => now(), 'rejection_reason' => $extra['reason'] ?? null, 'rejection_explanation' => $extra['explanation'] ?? null, 'rejection_internal_note' => $extra['internal_note'] ?? null],
                'preparing' => ['preparing_at' => now()],
                'ready_for_pickup' => ['ready_at' => now()],
                'completed_pickup' => ['completed_at' => now()],
                'cancelled' => ['cancelled_at' => now(), 'cancellation_reason' => $reason, 'cancellation_actor_type' => $actorType],
                'expired' => ['cancelled_at' => now(), 'cancellation_reason' => 'Acceptance timeout expired', 'cancellation_actor_type' => 'system'],
                default => [],
            };

            $order->update(array_merge(['status' => $newStatus], $timestamps));

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'actor_user_id' => $actor?->id,
                'actor_type' => $actorType,
                'reason' => $reason,
            ]);

            $this->applyInventorySideEffects($order, $oldStatus, $newStatus, $reason);

            $this->audit->log("order.{$newStatus}", $actor, $order, restaurantId: $order->restaurant_id);

            DB::afterCommit(function () use ($order, $oldStatus, $newStatus) {
                $this->events->statusChanged($order->fresh(), $oldStatus, $newStatus);
            });

            return $order->fresh();
        });
    }

    private function applyInventorySideEffects(Order $order, string $oldStatus, string $newStatus, ?string $reason): void
    {
        if ($newStatus === 'accepted') {
            $this->reservations->consumeForOrder($order);

            return;
        }

        $releaseStatuses = ['rejected', 'expired', 'payment_failed'];
        $cancelBeforeAccept = $newStatus === 'cancelled'
            && in_array($oldStatus, ['awaiting_restaurant', 'pending_payment', 'payment_failed'], true);

        if (in_array($newStatus, $releaseStatuses, true) || $cancelBeforeAccept) {
            $this->reservations->releaseForOrder(
                $order,
                $reason ?? "order.{$newStatus}",
            );
        }
    }
}
