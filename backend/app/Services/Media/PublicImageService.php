<?php

namespace App\Services\Media;

use App\Support\PublicImageUrls;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicImageService
{
    /** @return array{original: string, thumbnail: string, card: string, large: string, urls: array<string, string>} */
    public function storeRestaurantImage(UploadedFile $file, string $restaurantPublicId, string $type): array
    {
        $this->validate($file);
        $dir = "restaurants/{$restaurantPublicId}/{$type}";
        $baseName = Str::uuid()->toString();

        return $this->process($file, $dir, $baseName);
    }

    /** @return array{original: string, thumbnail: string, card: string, large: string, urls: array<string, string>} */
    public function storeMenuItemImage(UploadedFile $file, string $itemPublicId): array
    {
        $this->validate($file);
        $dir = "menu-items/{$itemPublicId}";

        return $this->process($file, $dir, Str::uuid()->toString());
    }

    public function deleteDirectory(string $directory): void
    {
        Storage::disk('public')->deleteDirectory($directory);
    }

    /**
     * @param  array<string, string>|null  $stored
     */
    public function toPublicPayload(?array $stored, string $kind = 'cover'): array
    {
        if (! $stored) {
            return PublicImageUrls::normalize(null, $kind);
        }

        $urls = [];
        foreach (['thumbnail', 'card', 'large', 'original'] as $key) {
            if (! empty($stored[$key])) {
                $urls[$key] = str_starts_with($stored[$key], 'http')
                    ? $stored[$key]
                    : PublicImageUrls::publicUrl($stored[$key]);
            }
        }

        return PublicImageUrls::normalize($urls, $kind);
    }

    private function validate(UploadedFile $file): void
    {
        $allowed = config('restaurant.media.allowed_mimes');
        $max = config('restaurant.media.max_bytes');
        if ($file->getSize() > $max) {
            throw ValidationException::withMessages(['file' => ['File exceeds maximum size.']]);
        }
        $mime = $file->getMimeType();
        if ($mime && ! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['file' => ['Invalid image type.']]);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['svg', 'svgz'], true)) {
            throw ValidationException::withMessages(['file' => ['SVG uploads are not allowed.']]);
        }
    }

    /** @return array{original: string, thumbnail: string, card: string, large: string, urls: array<string, string>} */
    private function process(UploadedFile $file, string $dir, string $baseName): array
    {
        $ext = strtolower($file->getClientOriginalExtension()) ?: 'jpg';
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $originalPath = "{$dir}/{$baseName}.{$ext}";
        Storage::disk('public')->putFileAs($dir, $file, "{$baseName}.{$ext}");

        $paths = [
            'original' => $originalPath,
            'thumbnail' => $this->variant($originalPath, $dir, $baseName, 'thumb', 200, 200),
            'card' => $this->variant($originalPath, $dir, $baseName, 'card', 640, 480),
            'large' => $this->variant($originalPath, $dir, $baseName, 'large', 1600, 900),
        ];

        $urls = [];
        foreach ($paths as $key => $path) {
            $urls[$key] = PublicImageUrls::publicUrl($path);
        }

        return array_merge($paths, ['urls' => $urls]);
    }

    private function variant(string $sourcePath, string $dir, string $baseName, string $suffix, int $maxW, int $maxH): string
    {
        $absolute = Storage::disk('public')->path($sourcePath);
        if (! is_file($absolute)) {
            return $sourcePath;
        }

        $info = @getimagesize($absolute);
        if (! $info) {
            return $sourcePath;
        }

        [$w, $h, $type] = $info;
        $src = $this->createImage($absolute, $type);
        if (! $src) {
            return $sourcePath;
        }

        $scale = min($maxW / max(1, $w), $maxH / max(1, $h), 1);
        $nw = (int) max(1, floor($w * $scale));
        $nh = (int) max(1, floor($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $target = "{$dir}/{$baseName}-{$suffix}.webp";
        $targetAbs = Storage::disk('public')->path($target);
        @mkdir(dirname($targetAbs), 0775, true);

        if (function_exists('imagewebp')) {
            imagewebp($dst, $targetAbs, 82);
        } else {
            $target = "{$dir}/{$baseName}-{$suffix}.jpg";
            $targetAbs = Storage::disk('public')->path($target);
            imagejpeg($dst, $targetAbs, 85);
        }

        imagedestroy($src);
        imagedestroy($dst);

        return $target;
    }

    private function createImage(string $path, int $type)
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }
}
