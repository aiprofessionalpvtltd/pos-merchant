<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\File;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Uploads, fetches and deletes files independently of the record that will
 * reference them, so a large photo does not have to survive the same request
 * as the product or order it belongs to.
 */
class FileService
{
    /**
     * @return array{data: array<string, mixed>, status: int}
     */
    public function upload(Merchant $merchant, UploadedFile $upload, string $purpose, ?string $clientUuid): array
    {
        if ($clientUuid) {
            $existing = File::where('merchant_id', $merchant->id)->where('client_uuid', $clientUuid)->first();

            if ($existing) {
                return ['data' => $this->payload($existing), 'status' => 200];
            }
        }

        $rules = config("exelo.files.purposes.$purpose");

        if ($upload->getSize() > $rules['max_bytes']) {
            throw new ApiException('file.too_large', 'That file is too large', 413, ['max_bytes' => $rules['max_bytes']]);
        }

        $mime = $upload->getMimeType();

        if (! in_array($mime, $rules['mimes'], true)) {
            throw new ApiException('file.type_unsupported', 'That file type is not accepted', 415, ['accepted' => $rules['mimes']]);
        }

        $image = @imagecreatefromstring(file_get_contents($upload->getRealPath()));

        if ($image === false) {
            throw new ApiException('file.corrupt', 'That file could not be read as an image', 422);
        }

        $processed = $this->process($image, $purpose, $rules);
        $disk = config('exelo.files.disk');
        $directory = 'files/'.$merchant->id;
        $extension = $rules['encode'] ?? $this->extensionForMime($mime);

        $path = "$directory/".Str::uuid().".$extension";
        Storage::disk($disk)->put($path, $this->encode($processed['image'], $extension));

        $thumbPath = null;
        if (! empty($rules['thumb_dimension'])) {
            $thumb = $this->resize($image, $rules['thumb_dimension']);
            $thumbPath = "$directory/".Str::uuid()."_thumb.$extension";
            Storage::disk($disk)->put($thumbPath, $this->encode($thumb, $extension));
        }

        $file = File::create([
            'public_id' => 'file_'.Str::upper(Str::ulid()->toBase32()),
            'merchant_id' => $merchant->id,
            'purpose' => $purpose,
            'disk' => $disk,
            'path' => $path,
            'thumb_path' => $thumbPath,
            'content_type' => 'image/'.($extension === 'jpg' ? 'jpeg' : $extension),
            'bytes' => Storage::disk($disk)->size($path),
            'width' => $processed['width'],
            'height' => $processed['height'],
            'client_uuid' => $clientUuid,
        ]);

        return ['data' => $this->payload($file), 'status' => 201];
    }

    public function find(Merchant $merchant, string $publicId): File
    {
        return File::where('merchant_id', $merchant->id)->where('public_id', $publicId)->first()
            ?? throw new ApiException('file.not_found', 'We could not find that file', 404);
    }

    /**
     * A one-hour signed URL to the original or the thumbnail.
     */
    public function signedUrl(File $file, string $variant): string
    {
        $path = $variant === 'thumb' && $file->thumb_path ? $file->thumb_path : $file->path;

        return URL::temporarySignedRoute('api.v1.files.raw', now()->addHour(), ['file' => $file->public_id, 'path' => $path]);
    }

    /**
     * @return array{file_id: string, deleted: bool}
     */
    public function delete(Merchant $merchant, string $publicId): array
    {
        $file = $this->find($merchant, $publicId);

        if ($file->attached_at !== null) {
            throw new ApiException('file.in_use', 'This file is attached to a record and cannot be deleted directly', 409, ['attached_to' => $this->attachedTo($file)]);
        }

        Storage::disk($file->disk)->delete(array_filter([$file->path, $file->thumb_path]));
        $file->delete();

        return ['file_id' => $publicId, 'deleted' => true];
    }

    /**
     * Marks a file as referenced by a product, order or merchant, so it survives the orphan sweep and cannot be deleted directly.
     */
    public function markAttached(?string $publicId, Merchant $merchant): void
    {
        if ($publicId === null) {
            return;
        }

        $this->find($merchant, $publicId)->update(['attached_at' => now()]);
    }

    /**
     * @return array{image: \GdImage, width: int, height: int}
     */
    private function process($image, string $purpose, array $rules): array
    {
        if ($purpose === 'signature') {
            $image = $this->flattenOnWhite($image);
        }

        if (! empty($rules['max_dimension'])) {
            $image = $this->resize($image, $rules['max_dimension']);
        }

        return ['image' => $image, 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    private function resize($image, int $maxDimension)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $maxDimension) {
            $copy = imagecreatetruecolor($width, $height);
            imagecopy($copy, $image, 0, 0, 0, 0, $width, $height);

            return $copy;
        }

        $scale = $maxDimension / max($width, $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /**
     * Flattens transparency onto a white background, so a signature drawn with an alpha channel prints cleanly.
     */
    private function flattenOnWhite($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $flat = imagecreatetruecolor($width, $height);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);

        return $flat;
    }

    private function encode($image, string $extension): string
    {
        ob_start();

        match ($extension) {
            'webp' => imagewebp($image, null, 82),
            'png' => imagepng($image),
            'jpg', 'jpeg' => imagejpeg($image, null, 85),
            default => imagejpeg($image, null, 85),
        };

        return ob_get_clean();
    }

    /**
     * Keeps a logo in its original format; product images and signatures always re-encode.
     */
    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(File $file): array
    {
        return [
            'file_id' => $file->public_id,
            'purpose' => $file->purpose,
            'url' => $file->url(),
            'thumb_url' => $file->thumbUrl(),
            'bytes' => $file->bytes,
            'width' => $file->width,
            'height' => $file->height,
            'content_type' => $file->content_type,
            'attached' => $file->attached_at !== null,
        ];
    }

    /**
     * @return array{type: string, id: int}|null
     */
    private function attachedTo(File $file): ?array
    {
        $product = Product::where('image_file_id', $file->public_id)->first();
        if ($product) {
            return ['type' => 'product', 'id' => $product->id];
        }

        $order = Order::where('signature_file_id', $file->public_id)->first();
        if ($order) {
            return ['type' => 'order', 'id' => $order->id];
        }

        $merchant = Merchant::where('logo_file_id', $file->public_id)->first();
        if ($merchant) {
            return ['type' => 'merchant', 'id' => $merchant->id];
        }

        return null;
    }
}
