<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Partner\UpdateApplicationRequest;
use App\Http\Resources\Partner\RestaurantApplicationResource;
use App\Http\Resources\Partner\RestaurantDocumentResource;
use App\Models\RestaurantApplication;
use App\Models\RestaurantDocument;
use App\Policies\RestaurantApplicationPolicy;
use App\Services\Partner\DocumentService;
use App\Services\Partner\RestaurantApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerApplicationController extends Controller
{
    public function __construct(
        private readonly RestaurantApplicationService $applications,
        private readonly DocumentService $documents,
        private readonly RestaurantApplicationPolicy $policy,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('submit_restaurant_application')) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $application = $this->applications->createDraft($user, $request);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($application)],
            message: 'Application draft ready.',
            status: 201,
        );
    }

    public function current(Request $request): JsonResponse
    {
        $application = RestaurantApplication::query()
            ->with(['addresses', 'documents', 'statusHistory', 'notes.author', 'commissionAgreements'])
            ->where('applicant_user_id', $request->user()->id)
            ->whereNotIn('status', ['withdrawn', 'expired'])
            ->latest('id')
            ->first();

        if (! $application) {
            return ApiResponse::success(data: ['application' => null]);
        }

        if (! $this->policy->viewOwn($request->user(), $application)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        return ApiResponse::success(data: [
            'application' => (new RestaurantApplicationResource($application))->additional(['include_internal' => false]),
        ]);
    }

    public function show(Request $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        $application->load(['addresses', 'documents', 'statusHistory', 'notes.author', 'commissionAgreements', 'restaurant']);

        return ApiResponse::success(data: [
            'application' => (new RestaurantApplicationResource($application))->additional(['include_internal' => false]),
        ]);
    }

    public function update(UpdateApplicationRequest $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        if (! $this->policy->updateOwn($request->user(), $application)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $updated = $this->applications->updateDraft($application, $request->validated(), $request->user(), $request);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Draft saved.',
        );
    }

    public function submit(Request $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        if (! $this->policy->submitOwn($request->user(), $application)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $request->validate([
            'terms' => ['accepted'],
            'confirm_accuracy' => ['accepted'],
        ]);

        $updated = $this->applications->submit($application, $request->user(), $request);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Application submitted.',
        );
    }

    public function resubmit(Request $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        if (! $this->policy->submitOwn($request->user(), $application)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $updated = $this->applications->submit($application, $request->user(), $request, resubmit: true);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Application resubmitted.',
        );
    }

    public function withdraw(Request $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        if (! $this->policy->withdrawOwn($request->user(), $application)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $updated = $this->applications->withdraw($application, $request->user(), $request);

        return ApiResponse::success(
            data: ['application' => new RestaurantApplicationResource($updated)],
            message: 'Application withdrawn.',
        );
    }

    public function uploadDocument(Request $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        if (! $this->policy->updateOwn($request->user(), $application) && ! $application->status->isEditableByApplicant()) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $data = $request->validate([
            'document_type' => ['required', 'in:'.implode(',', config('partner.document_types'))],
            'document' => ['required', 'file', 'max:'.((int) config('partner.max_document_bytes') / 1024)],
        ]);

        $document = $this->documents->upload(
            $application,
            $request->user(),
            $request->file('document'),
            $data['document_type'],
            $request,
        );

        return ApiResponse::success(
            data: ['document' => new RestaurantDocumentResource($document)],
            message: 'Document uploaded.',
            status: 201,
        );
    }

    public function listDocuments(Request $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);

        return ApiResponse::success(data: [
            'documents' => RestaurantDocumentResource::collection($application->documents),
        ]);
    }

    public function deleteDocument(Request $request, string $publicId, int $documentId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        $document = $application->documents()->whereKey($documentId)->firstOrFail();
        $this->documents->delete($document, $request->user(), $request);

        return ApiResponse::success(message: 'Document deleted.');
    }

    public function downloadDocument(Request $request, string $publicId, int $documentId): StreamedResponse|JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        $document = $application->documents()->whereKey($documentId)->firstOrFail();

        if (! $this->policy->downloadDocument($request->user(), $document)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        return $this->documents->download($document, $request->user(), $request);
    }

    public function acceptCommission(Request $request, string $publicId): JsonResponse
    {
        $application = $this->findOwned($request, $publicId);
        $agreement = $this->applications->acceptCommission($application, $request->user(), $request);

        return ApiResponse::success(
            data: ['agreement' => $agreement],
            message: 'Commission agreement accepted.',
        );
    }

    private function findOwned(Request $request, string $publicId): RestaurantApplication
    {
        $application = RestaurantApplication::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (! $this->policy->viewOwn($request->user(), $application)) {
            abort(403, 'Forbidden.');
        }

        return $application;
    }
}
