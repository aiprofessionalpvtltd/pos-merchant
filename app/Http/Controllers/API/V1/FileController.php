<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\StoreFileRequest;
use App\Models\Merchant;
use App\Services\FileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function __construct(private readonly FileService $files) {}

    public function store(StoreFileRequest $request): JsonResponse
    {
        $result = $this->files->upload(
            $this->merchant($request),
            $request->file('file'),
            $request->validated('purpose'),
            $request->validated('client_uuid'),
        );

        return ApiResponse::success($result['data'], 'Upload complete', $result['status']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $file = $this->files->find($this->merchant($request), $id);
        $variant = $request->query('variant', 'original') === 'thumb' ? 'thumb' : 'original';

        return ApiResponse::success([
            'file_id' => $file->public_id,
            'url' => $this->files->signedUrl($file, $variant),
            'expires_in' => 3600,
        ]);
    }

    /**
     * The signed redirect target for a file's bytes; not part of the JSON API.
     */
    public function raw(Request $request, string $file): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($request->hasValidSignature(), 403);

        $path = $request->query('path');
        $disk = config('exelo.files.disk');

        abort_unless($path && Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success($this->files->delete($this->merchant($request), $id), 'File deleted');
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
