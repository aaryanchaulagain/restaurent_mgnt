<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant;
use App\Models\RestaurantMedia;
use App\Models\User;
use App\Services\Auth\AuditLogger;
use App\Services\Media\PublicImageService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RestaurantMediaService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PublicImageService $publicImages,
    ) {}

    public function uploadLogo(Restaurant $restaurant, User $user, UploadedFile $file, Request $request): Restaurant
    {
        $processed = $this->publicImages->storeRestaurantImage($file, $restaurant->public_id, 'logo');
        $this->deletePublicSet($restaurant->logo_urls);
        $restaurant->forceFill([
            'logo_path' => $processed['original'],
            'logo_urls' => [
                'original' => $processed['original'],
                'thumbnail' => $processed['thumbnail'],
                'card' => $processed['card'],
                'large' => $processed['large'],
            ],
        ])->save();
        $this->auditLogger->log('restaurant.logo_changed', $user, $restaurant, restaurantId: $restaurant->id, request: $request);

        return $restaurant->fresh();
    }

    public function uploadCover(Restaurant $restaurant, User $user, UploadedFile $file, Request $request): Restaurant
    {
        $processed = $this->publicImages->storeRestaurantImage($file, $restaurant->public_id, 'cover');
        $this->deletePublicSet($restaurant->cover_urls);
        $restaurant->forceFill([
            'cover_image_path' => $processed['original'],
            'cover_urls' => [
                'original' => $processed['original'],
                'thumbnail' => $processed['thumbnail'],
                'card' => $processed['card'],
                'large' => $processed['large'],
            ],
        ])->save();
        $this->auditLogger->log('restaurant.cover_changed', $user, $restaurant, restaurantId: $restaurant->id, request: $request);

        return $restaurant->fresh();
    }

    public function uploadGallery(
        Restaurant $restaurant,
        User $user,
        UploadedFile $file,
        string $altText,
        Request $request,
    ): RestaurantMedia {
        if (strlen(trim($altText)) < 3) {
            throw ValidationException::withMessages(['alt_text' => ['Alt text is required for gallery images.']]);
        }

        $path = $this->storeImage($restaurant, $file, 'gallery');
        [$width, $height] = $this->dimensions($file);

        $media = RestaurantMedia::query()->create([
            'public_id' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'type' => 'gallery',
            'storage_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'image/jpeg',
            'size_bytes' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => $altText,
            'sort_order' => (int) RestaurantMedia::query()->where('restaurant_id', $restaurant->id)->where('type', 'gallery')->max('sort_order') + 1,
            'uploaded_by' => $user->id,
        ]);

        $this->auditLogger->log('restaurant.gallery_changed', $user, $media, restaurantId: $restaurant->id, request: $request);

        return $media;
    }

    public function updateGalleryMedia(RestaurantMedia $media, array $data, User $user, Request $request): RestaurantMedia
    {
        $media->fill($data)->save();
        $this->auditLogger->log('restaurant.gallery_changed', $user, $media, restaurantId: $media->restaurant_id, request: $request);

        return $media->fresh();
    }

    public function deleteGalleryMedia(RestaurantMedia $media, User $user, Request $request): void
    {
        $this->deletePath($media->storage_path);
        $this->deletePath($media->thumbnail_path);
        $restaurantId = $media->restaurant_id;
        $media->delete();
        $this->auditLogger->log('restaurant.gallery_changed', $user, null, restaurantId: $restaurantId, request: $request);
    }

    private function storeImage(Restaurant $restaurant, UploadedFile $file, string $folder): string
    {
        $this->validateImage($file);
        $ext = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().'.'.$ext;

        return $file->storeAs(
            'restaurant-media/'.$restaurant->public_id.'/'.$folder,
            $storedName,
            'local',
        );
    }

    private function validateImage(UploadedFile $file): void
    {
        $max = config('restaurant.media.max_bytes');
        $allowed = config('restaurant.media.allowed_extensions');
        $mimes = config('restaurant.media.allowed_mimes');

        if ($file->getSize() > $max) {
            throw ValidationException::withMessages(['file' => ['File exceeds maximum size.']]);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $allowed, true)) {
            throw ValidationException::withMessages(['file' => ['File type is not allowed.']]);
        }

        $mime = $file->getMimeType();
        if ($mime && ! in_array($mime, $mimes, true)) {
            throw ValidationException::withMessages(['file' => ['Invalid image MIME type.']]);
        }
    }

    private function dimensions(UploadedFile $file): array
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info) {
            return [null, null];
        }

        return [$info[0], $info[1]];
    }

    private function deletePublicSet(?array $urls): void
    {
        if (! $urls) {
            return;
        }
        foreach ($urls as $path) {
            if (is_string($path) && ! str_starts_with($path, 'http')) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function deletePath(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
