<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Partner\RestaurantApplicationResource;
use App\Http\Resources\Partner\RestaurantDocumentResource;
use App\Models\RestaurantApplication;
use App\Models\User;
use App\Policies\RestaurantApplicationPolicy;
use App\Services\Partner\DocumentService;
use App\Services\Partner\RestaurantApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRestaurantApplicationController extends Controller
{
    public function __construct(
        private readonly RestaurantApplicationService $applications,
        private readonly DocumentService $documents,
        private readonly RestaurantApplicationPolicy $policy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->policy->viewAnyAdmin($request->user())) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $query = RestaurantApplication::query()
            ->with(['applicant', 'assignedReviewer', 'addresses', 'documents'])
            ->whereNotNull('submitted_at')
            ->orWhereIn('status', ['draft', 'submitted', 'under_review', 'changes_requested', 'resubmitted', 'approved', 'rejected']);

        // Reset to cleaner base query
        $query = RestaurantApplication::query()->with(['applicant', 'assignedReviewer', 'addresses', 'documents']);

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($state = $request->string('state')->toString()) {
            $query->whereHas('addresses', fn ($q) => $q->where('state', strtoupper($state)));
        }
        if ($reviewer = $request->integer('assigned_reviewer_id')) {
            $query->where('assigned_reviewer_id', $reviewer);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('trading_name', 'like', "%{$search}%")
                    ->orWhere('legal_business_name', 'like', "%{$search}%")
                    ->orWhere('abn', 'like', '%'.preg_replace('/\D+/', '', $search).'%')
                    ->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhereHas('applicant', fn ($aq) => $aq->where('email', 'like', "%{$search}%"));
            });
        }
        if ($request->filled('from')) {
            $query->whereDate('submitted_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('submitted_at', '<=', $request->date('to'));
        }

        $sort = $request->string('sort')->toString() ?: 'newest';
        match ($sort) {
            'oldest' => $query->orderBy('submitted_at'),
            'updated' => $query->orderByDesc('updated_at'),
            'incomplete_documents' => $query->withCount([
                'documents as pending_docs_count' => fn ($q) => $q->where('status', 'pending'),
            ])->orderByDesc('pending_docs_count'),
            'awaiting_decision' => $query->whereIn('status', ['submitted', 'under_review', 'resubmitted'])->orderBy('submitted_at'),
            default => $query->orderByDesc('submitted_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate(min(50, max(1, $request->integer('per_page', 15))));

        return ApiResponse::success(
            data: [
                'applications' => RestaurantApplicationResource::collection($paginator->items()),
            ],
            meta: [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        );
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        if (! $this->policy->viewAnyAdmin($request->user())) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $application = RestaurantApplication::query()
            ->where('public_id', $publicId)
            ->with([
                'applicant',
                'assignedReviewer',
                'addresses',
                'documents',
                'statusHistory',
                'notes.author',
                'commissionAgreements',
                'restaurant',
            ])
            ->firstOrFail();

        return ApiResponse::success(data: [
            'application' => (new RestaurantApplicationResource($application))->additional(['include_internal' => true]),
        ]);
    }

    public function startReview(Request $request, string $publicId): JsonResponse
    {
        if (! $this->policy->review($request->user())) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $application = $this->find($publicId);
        $updated = $this->applications->startReview($application, $request->user(), $request);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Review started.',
        );
    }

    public function requestChanges(Request $request, string $publicId): JsonResponse
    {
        if (! $request->user()->hasPermission('request_application_changes')) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
            'items' => ['nullable', 'array'],
            'items.*' => ['string', 'max:120'],
        ]);

        $updated = $this->applications->requestChanges(
            $this->find($publicId),
            $request->user(),
            $data['reason'],
            $data['items'] ?? [],
            $request,
        );

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Changes requested.',
        );
    }

    public function approve(Request $request, string $publicId): JsonResponse
    {
        if (! $this->policy->approve($request->user())) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $this->confirmPassword($request);

        $updated = $this->applications->approve($this->find($publicId), $request->user(), $request);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated->load(['restaurant', 'commissionAgreements']))],
            message: 'Application approved.',
        );
    }

    public function reject(Request $request, string $publicId): JsonResponse
    {
        if (! $this->policy->reject($request->user())) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $this->confirmPassword($request);

        $data = $request->validate([
            'category' => ['required', Rule::in(config('partner.rejection_categories'))],
            'reason' => ['required', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $updated = $this->applications->reject(
            $this->find($publicId),
            $request->user(),
            $data['category'],
            $data['reason'],
            $data['internal_note'] ?? null,
            $request,
        );

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Application rejected.',
        );
    }

    public function assignReviewer(Request $request, string $publicId): JsonResponse
    {
        if (! $request->user()->hasPermission('assign_application_reviewers')) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $data = $request->validate(['reviewer_user_id' => ['required', 'exists:users,id']]);
        $reviewer = User::query()->findOrFail($data['reviewer_user_id']);
        if (! $reviewer->isSuperAdmin()) {
            throw ValidationException::withMessages(['reviewer_user_id' => ['Reviewer must be a super admin.']]);
        }

        $updated = $this->applications->assignReviewer($this->find($publicId), $reviewer, $request->user(), $request);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Reviewer assigned.',
        );
    }

    public function verifyDocument(Request $request, string $publicId, int $documentId): JsonResponse
    {
        if (! $request->user()->hasPermission('verify_restaurant_documents')) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $document = $this->findDocument($publicId, $documentId);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $updated = $this->documents->verify($document, $request->user(), $data['notes'] ?? null, $request);

        return ApiResponse::success(
            data: ['document' => new RestaurantDocumentResource($updated)],
            message: 'Document verified.',
        );
    }

    public function rejectDocument(Request $request, string $publicId, int $documentId): JsonResponse
    {
        if (! $request->user()->hasPermission('verify_restaurant_documents')) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $document = $this->findDocument($publicId, $documentId);
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        $updated = $this->documents->reject($document, $request->user(), $data['notes'], $request);

        return ApiResponse::success(
            data: ['document' => new RestaurantDocumentResource($updated)],
            message: 'Document rejected.',
        );
    }

    public function downloadDocument(Request $request, string $publicId, int $documentId): StreamedResponse|JsonResponse
    {
        $document = $this->findDocument($publicId, $documentId);
        if (! $this->policy->downloadDocument($request->user(), $document)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        return $this->documents->download($document, $request->user(), $request);
    }

    public function getCommission(Request $request, string $publicId): JsonResponse
    {
        if (! $request->user()->hasPermission('manage_commission_agreements')) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $application = $this->find($publicId);
        $agreement = $application->commissionAgreements()->latest('id')->first();

        return ApiResponse::success(data: ['agreement' => $agreement]);
    }

    public function storeCommission(Request $request, string $publicId): JsonResponse
    {
        if (! $request->user()->hasPermission('manage_commission_agreements')) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $data = $request->validate([
            'commission_type' => ['required', Rule::in(config('partner.commission_types'))],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_fee_cents' => ['nullable', 'integer', 'min:0'],
            'settlement_frequency' => ['required', Rule::in(config('partner.settlement_frequencies'))],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'status' => ['nullable', Rule::in(['draft', 'offered'])],
            'processing_fee_responsibility' => ['nullable', 'string', 'max:40'],
            'delivery_fee_responsibility' => ['nullable', 'string', 'max:40'],
            'discount_calculation_method' => ['nullable', 'string', 'max:40'],
        ]);

        $agreement = $this->applications->upsertCommission($this->find($publicId), $request->user(), $data, $request);

        return ApiResponse::success(data: ['agreement' => $agreement], message: 'Commission agreement saved.', status: 201);
    }

    public function updateCommission(Request $request, string $publicId): JsonResponse
    {
        return $this->storeCommission($request, $publicId);
    }

    public function addNote(Request $request, string $publicId): JsonResponse
    {
        if (! $this->policy->review($request->user())) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', Rule::in(['internal', 'applicant_visible'])],
        ]);

        $note = $this->applications->addNote($this->find($publicId), $request->user(), $data['note'], $data['visibility']);

        return ApiResponse::success(data: ['note' => $note], status: 201);
    }

    private function find(string $publicId): RestaurantApplication
    {
        return RestaurantApplication::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function findDocument(string $publicId, int $documentId)
    {
        $application = $this->find($publicId);

        return $application->documents()->whereKey($documentId)->firstOrFail();
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => ['Password confirmation failed.']]);
        }
    }
}
