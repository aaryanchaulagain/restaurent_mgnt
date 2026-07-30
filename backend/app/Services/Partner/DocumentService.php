<?php

namespace App\Services\Partner;

use App\Contracts\DocumentScanner;
use App\Enums\Partner\ApplicationStatus;
use App\Models\RestaurantApplication;
use App\Models\RestaurantDocument;
use App\Models\User;
use App\Notifications\Partner\DocumentRejectedNotification;
use App\Services\Auth\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentScanner $scanner,
    ) {}

    public function upload(
        RestaurantApplication $application,
        User $uploader,
        UploadedFile $file,
        string $documentType,
        Request $request,
    ): RestaurantDocument {
        if (! $application->status->isEditableByApplicant() && $application->status !== ApplicationStatus::Draft) {
            // Allow upload only while editable
            if (! in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::ChangesRequested], true)) {
                throw ValidationException::withMessages([
                    'document' => ['Documents cannot be uploaded in the current application status.'],
                ]);
            }
        }

        $this->validateFile($file);

        $scan = $this->scanner->scan($file);
        if (! $scan['clean']) {
            throw ValidationException::withMessages(['document' => ['Uploaded file failed security checks.']]);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs(
            'restaurant-documents/'.$application->public_id,
            $storedName,
            'local',
        );

        $existing = $application->documents()
            ->where('document_type', $documentType)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            Storage::disk('local')->delete($existing->storage_path);
            $existing->update([
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'status' => 'pending',
                'verification_notes' => null,
                'verified_by' => null,
                'verified_at' => null,
                'uploaded_by' => $uploader->id,
            ]);
            $document = $existing->fresh();
        } else {
            $document = RestaurantDocument::query()->create([
                'application_id' => $application->id,
                'restaurant_id' => $application->restaurant_id,
                'document_type' => $documentType,
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'status' => 'pending',
                'uploaded_by' => $uploader->id,
            ]);
        }

        $this->auditLogger->log('partner.document_uploaded', $uploader, $document, restaurantId: $application->restaurant_id, metadata: [
            'document_type' => $documentType,
            'size_bytes' => $document->size_bytes,
        ], request: $request);

        return $document;
    }

    public function delete(RestaurantDocument $document, User $actor, Request $request): void
    {
        $application = $document->application;
        if (! $application || ! $application->status->isEditableByApplicant()) {
            throw ValidationException::withMessages([
                'document' => ['Documents cannot be deleted in the current application status.'],
            ]);
        }

        Storage::disk('local')->delete($document->storage_path);
        $document->delete();

        $this->auditLogger->log('partner.document_deleted', $actor, $document, restaurantId: $application->restaurant_id, request: $request);
    }

    public function verify(RestaurantDocument $document, User $admin, ?string $notes, Request $request): RestaurantDocument
    {
        $document->update([
            'status' => 'verified',
            'verification_notes' => $notes,
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $this->auditLogger->log('partner.document_verified', $admin, $document, restaurantId: $document->restaurant_id, request: $request);

        return $document->fresh();
    }

    public function reject(RestaurantDocument $document, User $admin, string $notes, Request $request): RestaurantDocument
    {
        $document->update([
            'status' => 'rejected',
            'verification_notes' => $notes,
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $this->auditLogger->log('partner.document_rejected', $admin, $document, restaurantId: $document->restaurant_id, request: $request);
        $document->application?->applicant?->notify(new DocumentRejectedNotification($document));

        return $document->fresh();
    }

    public function download(RestaurantDocument $document, User $actor, Request $request): StreamedResponse
    {
        $this->auditLogger->log('partner.document_viewed', $actor, $document, restaurantId: $document->restaurant_id, metadata: [
            'document_id' => $document->id,
        ], request: $request);

        return Storage::disk('local')->download(
            $document->storage_path,
            $document->original_name,
        );
    }

    private function validateFile(UploadedFile $file): void
    {
        $max = (int) config('partner.max_document_bytes');
        if ($file->getSize() > $max) {
            throw ValidationException::withMessages([
                'document' => ['File exceeds the maximum allowed size.'],
            ]);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $blocked = config('partner.blocked_document_extensions');
        if (in_array($ext, $blocked, true)) {
            throw ValidationException::withMessages([
                'document' => ['This file type is not allowed.'],
            ]);
        }

        $allowedExt = config('partner.allowed_document_extensions');
        if (! in_array($ext, $allowedExt, true)) {
            throw ValidationException::withMessages([
                'document' => ['Only PDF and image documents are accepted.'],
            ]);
        }

        $mime = $file->getMimeType();
        $allowedMimes = config('partner.allowed_document_mimes');
        if ($mime && ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'document' => ['File MIME type is not allowed.'],
            ]);
        }
    }
}
