<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantMedia;
use App\Models\User;
use App\Services\Restaurant\RestaurantMediaService;
use App\Support\ApiResponse;
use App\Support\RestaurantContext;
use Illuminate\Http\Request;

class RestaurantMediaController extends Controller
{
    public function __construct(
        private readonly RestaurantMediaService $mediaService,
    ) {}

    public function uploadLogo(Request $request)
    {
        $request->validate(['file' => ['required', 'file']]);
        $restaurant = RestaurantContext::restaurant($request);
        /** @var User $user */
        $user = $request->user();
        $updated = $this->mediaService->uploadLogo($restaurant, $user, $request->file('file'), $request);

        return ApiResponse::success(['logo_path' => $updated->logo_path]);
    }

    public function uploadCover(Request $request)
    {
        $request->validate(['file' => ['required', 'file']]);
        $restaurant = RestaurantContext::restaurant($request);
        /** @var User $user */
        $user = $request->user();
        $updated = $this->mediaService->uploadCover($restaurant, $user, $request->file('file'), $request);

        return ApiResponse::success(['cover_image_path' => $updated->cover_image_path]);
    }

    public function uploadGallery(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file'],
            'alt_text' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $restaurant = RestaurantContext::restaurant($request);
        /** @var User $user */
        $user = $request->user();
        $media = $this->mediaService->uploadGallery($restaurant, $user, $request->file('file'), $request->string('alt_text')->toString(), $request);

        return ApiResponse::success(['media' => $this->mediaPayload($media)], status: 201);
    }

    public function updateGallery(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $media = RestaurantMedia::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $publicId)
            ->where('type', 'gallery')
            ->firstOrFail();

        $data = $request->validate([
            'alt_text' => ['sometimes', 'string', 'min:3', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $updated = $this->mediaService->updateGalleryMedia($media, $data, $user, $request);

        return ApiResponse::success(['media' => $this->mediaPayload($updated)]);
    }

    public function deleteGallery(Request $request, string $publicId)
    {
        $restaurantId = RestaurantContext::id($request);
        $media = RestaurantMedia::query()
            ->where('restaurant_id', $restaurantId)
            ->where('public_id', $publicId)
            ->where('type', 'gallery')
            ->firstOrFail();

        /** @var User $user */
        $user = $request->user();
        $this->mediaService->deleteGalleryMedia($media, $user, $request);

        return ApiResponse::success(message: 'Deleted.');
    }

    private function mediaPayload(RestaurantMedia $media): array
    {
        return [
            'public_id' => $media->public_id,
            'type' => $media->type,
            'storage_path' => $media->storage_path,
            'alt_text' => $media->alt_text,
            'sort_order' => $media->sort_order,
            'is_active' => $media->is_active,
        ];
    }
}
