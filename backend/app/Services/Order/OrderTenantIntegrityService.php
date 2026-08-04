<?php

namespace App\Services\Order;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Cart;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Cart\CartBranchContext;
use App\Support\DemoSeededRestaurantSlugs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read-only tenant relationship audit for historical orders.
 * Repairs require explicit per-order confirmation and unique evidence.
 */
class OrderTenantIntegrityService
{
    public const CLASS_SOFT_DELETED = 'ORDER_RESTAURANT_SOFT_DELETED';

    public const CLASS_RESTAURANT_MISSING = 'ORDER_RESTAURANT_MISSING';

    public const CLASS_BRANCH_MISSING = 'RESTAURANT_BRANCH_MISSING';

    public const CLASS_LINK_MISMATCH = 'BRANCH_RESTAURANT_LINK_MISMATCH';

    public const CLASS_BUSINESS_MISSING = 'RESTAURANT_BUSINESS_MISSING';

    public const CLASS_DEMO = 'DEMO_OR_TEST_ORDER';

    public const CLASS_LEGACY = 'LEGACY_PRE_BRANCH_ORDER';

    public const CLASS_PAYMENT_ORPHAN = 'PAYMENT_ORPHAN';

    public const CLASS_RESERVATION_ORPHAN = 'RESERVATION_ORPHAN';

    public const CLASS_OK = 'OK';

    public const CLASS_UNRESOLVED = 'UNRESOLVED';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CartBranchContext $branchContext,
    ) {}

    /**
     * @return array{
     *   summary: array<string, int>,
     *   findings: list<array<string, mixed>>,
     *   payment_orphans: list<array<string, mixed>>,
     *   reservation_orphans: list<array<string, mixed>>
     * }
     */
    public function audit(?string $orderPublicId = null, bool $includeDemo = true): array
    {
        $findings = [];
        $summary = [];

        $query = Order::query()->orderBy('id');
        if ($orderPublicId) {
            $query->where('public_id', $orderPublicId);
        }

        $query->chunkById(200, function ($orders) use (&$findings, &$summary, $includeDemo) {
            foreach ($orders as $order) {
                $row = $this->classifyOrder($order);
                if ($row['classification'] === self::CLASS_OK) {
                    $summary[self::CLASS_OK] = ($summary[self::CLASS_OK] ?? 0) + 1;

                    continue;
                }
                if (! $includeDemo && ($row['is_demo_or_seed'] ?? false)) {
                    continue;
                }
                $findings[] = $row;
                $class = (string) $row['classification'];
                $summary[$class] = ($summary[$class] ?? 0) + 1;
            }
        });

        $paymentOrphans = $this->paymentOrphans();
        $reservationOrphans = $this->reservationOrphans();
        if ($paymentOrphans !== []) {
            $summary[self::CLASS_PAYMENT_ORPHAN] = count($paymentOrphans);
        }
        if ($reservationOrphans !== []) {
            $summary[self::CLASS_RESERVATION_ORPHAN] = count($reservationOrphans);
        }

        return [
            'summary' => $summary,
            'findings' => $findings,
            'payment_orphans' => $paymentOrphans,
            'reservation_orphans' => $reservationOrphans,
        ];
    }

    /** @return array<string, mixed> */
    public function classifyOrder(Order $order): array
    {
        $restaurant = Restaurant::withTrashed()->find($order->restaurant_id);
        $paymentCount = Payment::query()->where('order_id', $order->id)->count();
        $paymentSum = (int) Payment::query()->where('order_id', $order->id)->sum('amount_cents');
        $reservationCount = InventoryReservation::query()->where('order_id', $order->id)->count();
        $cart = $order->cart_id ? Cart::query()->find($order->cart_id) : null;

        $isDemo = $restaurant
            && DemoSeededRestaurantSlugs::isDemoSlug($restaurant->slug);
        $isSeed = DemoSeededRestaurantSlugs::isSeedOrderNumber($order->order_number)
            || DemoSeededRestaurantSlugs::isPhase6PaymentFixtureUuid($order->public_id)
            || (is_string($order->idempotency_key) && str_starts_with($order->idempotency_key, 'seed-'));

        $base = [
            'order_public_id' => $order->public_id,
            'order_number' => $order->order_number,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'placed_at' => optional($order->placed_at)?->toIso8601String(),
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'total_cents' => $order->total_cents,
            'restaurant_id_present' => $order->restaurant_id !== null,
            'restaurant_exists' => (bool) $restaurant,
            'restaurant_soft_deleted' => $restaurant?->trashed() ?? false,
            'restaurant_public_id' => $restaurant?->public_id,
            'restaurant_slug' => $restaurant?->slug,
            'branch_public_id' => null,
            'business_public_id' => null,
            'cart_restaurant_matches' => $cart
                ? (int) $cart->restaurant_id === (int) $order->restaurant_id
                : null,
            'payment_count' => $paymentCount,
            'payment_total_cents' => $paymentSum,
            'settlement_count' => 0,
            'reservation_count' => $reservationCount,
            'is_demo_or_seed' => $isDemo || $isSeed,
            'financially_active' => $paymentCount > 0 || in_array($order->payment_status, ['paid', 'partially_refunded', 'refunded', 'disputed'], true),
            'confidence' => [],
        ];

        if (! $restaurant) {
            return array_merge($base, [
                'classification' => self::CLASS_RESTAURANT_MISSING,
                'proposed_action' => 'PRESERVE_FOR_MANUAL_REVIEW',
                'confidence' => 'high',
                'repair_safe' => false,
                'reason' => 'Restaurant row is physically missing. Do not guess a replacement. Preserve order and financial records.',
                'evidence' => array_filter([
                    'cart_restaurant_id' => $cart?->restaurant_id,
                    'seed_markers' => $isSeed,
                ]),
            ]);
        }

        $branch = $restaurant->branch_id
            ? Branch::withTrashed()->find($restaurant->branch_id)
            : Branch::withTrashed()->where('restaurant_id', $restaurant->id)->first();
        $business = $restaurant->business_id
            ? Business::withTrashed()->find($restaurant->business_id)
            : ($branch ? Business::withTrashed()->find($branch->business_id) : null);

        $base['branch_public_id'] = $branch?->public_id;
        $base['business_public_id'] = $business?->public_id;

        $issues = [];
        if ($restaurant->trashed()) {
            $issues[] = self::CLASS_SOFT_DELETED;
        }
        if (! $branch) {
            $issues[] = self::CLASS_BRANCH_MISSING;
        } elseif (! $this->branchContext->linksAreConsistent($branch, $restaurant) && ! $restaurant->trashed()) {
            // Soft-deleted restaurants may still have consistent links; check always for mismatch.
        }
        if ($branch && ! $this->branchContext->linksAreConsistent($branch, $restaurant)) {
            $issues[] = self::CLASS_LINK_MISMATCH;
        }
        if ($restaurant->business_id && ! $business) {
            $issues[] = self::CLASS_BUSINESS_MISSING;
        }
        if (! $restaurant->branch_id && ! $branch && ! $restaurant->trashed()) {
            // Truly pre-branch legacy if restaurant never got a branch after hierarchy migration window.
            if ($order->placed_at && $order->placed_at->lt(now()->subYears(50))) {
                $issues[] = self::CLASS_LEGACY;
            }
        }

        if ($issues === []) {
            // Demo orders with healthy live restaurants are OK operationally; still tag provenance.
            if (($isDemo || $isSeed) && ! $restaurant->trashed()) {
                return array_merge($base, [
                    'classification' => self::CLASS_DEMO,
                    'proposed_action' => 'NONE_HEALTHY_DEMO',
                    'confidence' => 'high',
                    'repair_safe' => false,
                    'reason' => 'Deterministic demo/seed markers with a live restaurant — no repair needed.',
                    'evidence' => [
                        'demo_slug' => $isDemo,
                        'seed_order_number' => DemoSeededRestaurantSlugs::isSeedOrderNumber($order->order_number),
                        'fixture_uuid' => DemoSeededRestaurantSlugs::isPhase6PaymentFixtureUuid($order->public_id),
                    ],
                ]);
            }

            return array_merge($base, [
                'classification' => self::CLASS_OK,
                'proposed_action' => 'NONE',
                'confidence' => 'high',
                'repair_safe' => false,
                'reason' => 'Tenant relationships are consistent.',
                'evidence' => [],
            ]);
        }

        // Prefer primary classification by severity.
        $classification = $issues[0];
        if (in_array(self::CLASS_SOFT_DELETED, $issues, true)) {
            $classification = self::CLASS_SOFT_DELETED;
        } elseif (in_array(self::CLASS_LINK_MISMATCH, $issues, true)) {
            $classification = self::CLASS_LINK_MISMATCH;
        } elseif (in_array(self::CLASS_BRANCH_MISSING, $issues, true)) {
            $classification = self::CLASS_BRANCH_MISSING;
        }

        $demoNote = ($isDemo || $isSeed)
            ? ' Deterministic demo/seed provenance (slug and/or seed order markers).'
            : '';

        if ($classification === self::CLASS_SOFT_DELETED) {
            return array_merge($base, [
                'classification' => self::CLASS_SOFT_DELETED,
                'secondary_classifications' => array_values(array_diff($issues, [self::CLASS_SOFT_DELETED])),
                'proposed_action' => 'RESOLVE_VIA_WITH_TRASHED_NO_DATA_MUTATION',
                'confidence' => 'high',
                'repair_safe' => false,
                'reason' => 'Restaurant exists but is soft-deleted (often demo:archive-seeded-partners). Do not restore automatically. Historical reads should use withTrashed(); leave order/payment totals unchanged.'.$demoNote,
                'evidence' => [
                    'restaurant_public_id' => $restaurant->public_id,
                    'restaurant_slug' => $restaurant->slug,
                    'deleted_at' => optional($restaurant->deleted_at)?->toIso8601String(),
                    'branch_public_id' => $branch?->public_id,
                    'links_consistent' => $branch ? $this->branchContext->linksAreConsistent($branch, $restaurant) : false,
                    'demo_slug' => $isDemo,
                    'seed_markers' => $isSeed,
                ],
            ]);
        }

        if ($classification === self::CLASS_LINK_MISMATCH && $branch) {
            $repairPlan = $this->uniqueLinkRepairPlan($restaurant, $branch);

            return array_merge($base, [
                'classification' => self::CLASS_LINK_MISMATCH,
                'proposed_action' => $repairPlan['action'],
                'confidence' => $repairPlan['confidence'],
                'repair_safe' => $repairPlan['safe'],
                'reason' => $repairPlan['reason'].$demoNote,
                'evidence' => $repairPlan['evidence'],
            ]);
        }

        if ($classification === self::CLASS_BRANCH_MISSING) {
            $inverse = Branch::withTrashed()->where('restaurant_id', $restaurant->id)->get();
            if ($inverse->count() === 1) {
                $only = $inverse->first();

                return array_merge($base, [
                    'classification' => self::CLASS_BRANCH_MISSING,
                    'proposed_action' => 'SET_RESTAURANT_BRANCH_ID_FROM_UNIQUE_INVERSE',
                    'confidence' => 'high',
                    'repair_safe' => true,
                    'reason' => 'Restaurant.branch_id is null but exactly one branch points at this restaurant.'.$demoNote,
                    'evidence' => [
                        'branch_public_id' => $only->public_id,
                        'branch_business_id' => $only->business_id,
                        'restaurant_business_id' => $restaurant->business_id,
                        'business_ids_match' => $restaurant->business_id === null
                            || (int) $restaurant->business_id === (int) $only->business_id,
                    ],
                ]);
            }

            return array_merge($base, [
                'classification' => self::CLASS_BRANCH_MISSING,
                'proposed_action' => $order->placed_at && ! $restaurant->trashed()
                    ? 'CLASSIFY_LEGACY_OR_MANUAL_REVIEW'
                    : 'PRESERVE_FOR_MANUAL_REVIEW',
                'confidence' => 'medium',
                'repair_safe' => false,
                'reason' => 'No unique inverse branch relationship. Do not attach to first/any branch.'.$demoNote,
                'evidence' => ['inverse_branch_count' => $inverse->count()],
            ]);
        }

        return array_merge($base, [
            'classification' => $classification,
            'proposed_action' => 'PRESERVE_FOR_MANUAL_REVIEW',
            'confidence' => 'medium',
            'repair_safe' => false,
            'reason' => 'Insufficient unique evidence for automatic repair.'.$demoNote,
            'evidence' => ['issues' => $issues],
        ]);
    }

    /**
     * Explicit repair for a single order public ID. Never mutates financial fields.
     *
     * @return array{ok: bool, code: string, message: string, before?: array<string, mixed>, after?: array<string, mixed>}
     */
    public function repairConfirmed(string $orderPublicId, ?User $actor = null, ?string $invocationId = null): array
    {
        $order = Order::query()->where('public_id', $orderPublicId)->first();
        if (! $order) {
            return ['ok' => false, 'code' => 'ORDER_INTEGRITY_REPAIR_NOT_SAFE', 'message' => 'Order not found.'];
        }

        $beforeFinancial = [
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'total_cents' => $order->total_cents,
            'subtotal_cents' => $order->subtotal_cents,
            'commission_amount_cents' => $order->commission_amount_cents,
        ];

        $classification = $this->classifyOrder($order);
        if (! ($classification['repair_safe'] ?? false)) {
            return [
                'ok' => false,
                'code' => 'ORDER_INTEGRITY_REPAIR_NOT_SAFE',
                'message' => $classification['reason'] ?? 'Repair is not safe for this order.',
                'classification' => $classification['classification'],
            ];
        }

        $restaurant = Restaurant::withTrashed()->find($order->restaurant_id);
        if (! $restaurant) {
            return ['ok' => false, 'code' => 'ORDER_RESTAURANT_MISSING', 'message' => 'Restaurant missing.'];
        }

        $invocationId ??= (string) Str::uuid();
        $before = [
            'restaurant_branch_id' => $restaurant->branch_id,
            'restaurant_business_id' => $restaurant->business_id,
        ];

        try {
            DB::transaction(function () use ($classification, $restaurant, $order, $actor, $invocationId, $before, $beforeFinancial) {
                if ($classification['proposed_action'] === 'SET_RESTAURANT_BRANCH_ID_FROM_UNIQUE_INVERSE') {
                    $branchPublicId = $classification['evidence']['branch_public_id'] ?? null;
                    $branch = Branch::withTrashed()->where('public_id', $branchPublicId)->first();
                    if (! $branch || (int) $branch->restaurant_id !== (int) $restaurant->id) {
                        throw new \RuntimeException('ORDER_INTEGRITY_REPAIR_CONFLICT');
                    }
                    if ($restaurant->business_id !== null
                        && (int) $restaurant->business_id !== (int) $branch->business_id) {
                        throw new \RuntimeException('ORDER_INTEGRITY_REPAIR_CONFLICT');
                    }
                    $restaurant->forceFill(['branch_id' => $branch->id])->save();
                } elseif ($classification['proposed_action'] === 'SET_BRANCH_RESTAURANT_ID_FROM_UNIQUE_FORWARD') {
                    $branchPublicId = $classification['evidence']['branch_public_id'] ?? null;
                    $branch = Branch::withTrashed()->where('public_id', $branchPublicId)->first();
                    if (! $branch || (int) $restaurant->branch_id !== (int) $branch->id) {
                        throw new \RuntimeException('ORDER_INTEGRITY_REPAIR_CONFLICT');
                    }
                    if ($branch->restaurant_id !== null && (int) $branch->restaurant_id !== (int) $restaurant->id) {
                        throw new \RuntimeException('ORDER_INTEGRITY_REPAIR_CONFLICT');
                    }
                    $branch->forceFill(['restaurant_id' => $restaurant->id])->save();
                } else {
                    throw new \RuntimeException('ORDER_INTEGRITY_REPAIR_NOT_SAFE');
                }

                $order->refresh();
                $afterFinancial = [
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'total_cents' => $order->total_cents,
                    'subtotal_cents' => $order->subtotal_cents,
                    'commission_amount_cents' => $order->commission_amount_cents,
                ];
                if ($afterFinancial !== $beforeFinancial) {
                    throw new \RuntimeException('ORDER_INTEGRITY_REPAIR_CONFLICT');
                }

                $restaurant->refresh();
                $this->audit->log(
                    'order.tenant_integrity.repaired',
                    $actor,
                    $order,
                    $before,
                    [
                        'restaurant_branch_id' => $restaurant->branch_id,
                        'restaurant_business_id' => $restaurant->business_id,
                    ],
                    $restaurant->id,
                    [
                        'order_public_id' => $order->public_id,
                        'classification' => $classification['classification'],
                        'proposed_action' => $classification['proposed_action'],
                        'evidence' => $classification['evidence'] ?? [],
                        'payment_count' => $classification['payment_count'] ?? 0,
                        'reservation_count' => $classification['reservation_count'] ?? 0,
                        'invocation_id' => $invocationId,
                        'financial_unchanged' => true,
                    ],
                );
            });
        } catch (\RuntimeException $e) {
            return [
                'ok' => false,
                'code' => $e->getMessage() === 'ORDER_INTEGRITY_REPAIR_CONFLICT'
                    ? 'ORDER_INTEGRITY_REPAIR_CONFLICT'
                    : 'ORDER_INTEGRITY_REPAIR_NOT_SAFE',
                'message' => 'Repair aborted.',
            ];
        }

        $restaurant->refresh();

        return [
            'ok' => true,
            'code' => 'REPAIRED',
            'message' => 'Tenant link repaired without changing financial fields.',
            'before' => $before,
            'after' => [
                'restaurant_branch_id' => $restaurant->branch_id,
                'restaurant_business_id' => $restaurant->business_id,
            ],
            'financial' => $beforeFinancial,
        ];
    }

    /**
     * @return array{action: string, confidence: string, safe: bool, reason: string, evidence: array<string, mixed>}
     */
    private function uniqueLinkRepairPlan(Restaurant $restaurant, Branch $branch): array
    {
        $evidence = [
            'restaurant_public_id' => $restaurant->public_id,
            'branch_public_id' => $branch->public_id,
            'restaurant_branch_id' => $restaurant->branch_id,
            'branch_restaurant_id' => $branch->restaurant_id,
            'restaurant_business_id' => $restaurant->business_id,
            'branch_business_id' => $branch->business_id,
        ];

        if ($restaurant->business_id !== null
            && (int) $restaurant->business_id !== (int) $branch->business_id) {
            return [
                'action' => 'PRESERVE_FOR_MANUAL_REVIEW',
                'confidence' => 'high',
                'safe' => false,
                'reason' => 'Cross-business mismatch — never auto-repair.',
                'evidence' => $evidence,
            ];
        }

        // Forward link set, inverse null → unique forward evidence.
        if ($restaurant->branch_id
            && (int) $restaurant->branch_id === (int) $branch->id
            && $branch->restaurant_id === null) {
            $other = Branch::withTrashed()
                ->where('restaurant_id', $restaurant->id)
                ->where('id', '!=', $branch->id)
                ->exists();
            if ($other) {
                return [
                    'action' => 'PRESERVE_FOR_MANUAL_REVIEW',
                    'confidence' => 'high',
                    'safe' => false,
                    'reason' => 'Conflicting inverse branch points at this restaurant.',
                    'evidence' => $evidence,
                ];
            }

            return [
                'action' => 'SET_BRANCH_RESTAURANT_ID_FROM_UNIQUE_FORWARD',
                'confidence' => 'high',
                'safe' => true,
                'reason' => 'Restaurant.branch_id uniquely points at this branch; branch.restaurant_id is null.',
                'evidence' => $evidence,
            ];
        }

        // Inverse set, forward null.
        if ($branch->restaurant_id
            && (int) $branch->restaurant_id === (int) $restaurant->id
            && $restaurant->branch_id === null) {
            return [
                'action' => 'SET_RESTAURANT_BRANCH_ID_FROM_UNIQUE_INVERSE',
                'confidence' => 'high',
                'safe' => true,
                'reason' => 'Branch.restaurant_id uniquely points at this restaurant; restaurant.branch_id is null.',
                'evidence' => $evidence,
            ];
        }

        return [
            'action' => 'PRESERVE_FOR_MANUAL_REVIEW',
            'confidence' => 'high',
            'safe' => false,
            'reason' => 'Conflicting or ambiguous mutual links — not auto-repairable.',
            'evidence' => $evidence,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function paymentOrphans(): array
    {
        $out = [];
        Payment::query()->orderBy('id')->chunkById(200, function ($payments) use (&$out) {
            foreach ($payments as $payment) {
                if (! Order::query()->whereKey($payment->order_id)->exists()) {
                    $out[] = [
                        'classification' => self::CLASS_PAYMENT_ORPHAN,
                        'payment_public_id' => $payment->public_id,
                        'proposed_action' => 'PRESERVE_FOR_MANUAL_REVIEW',
                        'repair_safe' => false,
                        'reason' => 'Payment references a missing order. Never delete automatically.',
                        'amount_cents' => $payment->amount_cents,
                        'status' => $payment->status,
                    ];
                }
            }
        });

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function reservationOrphans(): array
    {
        $out = [];
        InventoryReservation::query()->orderBy('id')->chunkById(200, function ($rows) use (&$out) {
            foreach ($rows as $row) {
                if ($row->order_id && ! Order::query()->whereKey($row->order_id)->exists()) {
                    $out[] = [
                        'classification' => self::CLASS_RESERVATION_ORPHAN,
                        'reservation_public_id' => $row->public_id,
                        'proposed_action' => 'PRESERVE_FOR_MANUAL_REVIEW',
                        'repair_safe' => false,
                        'reason' => 'Reservation references a missing order.',
                        'status' => $row->status,
                    ];
                }
            }
        });

        return $out;
    }
}
