<?php

namespace App\Http\Controllers;

use App\Classes\Service\ImageService;
use App\Models\Image;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;


class ImageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        Log::info($request->all());

        $data = $request->validate([
            // We accept either 'files' (array) or 'file' (single). Validate presence below.
            'files' => 'nullable',
            'files.*' => 'file|image|max:5120',
            'file' => 'nullable|file|image|max:5120',
            'tag' => 'nullable|string|max:100',
            'imaginable_type' => 'nullable|string',
            'imaginable_id' => 'nullable|integer',
        ]);

        // Normalize files: prefer files[] then file
        $files = $request->file('files') ?? $request->file('file');

        if (!$files) {
            return response()->json(['success' => false, 'message' => 'No files uploaded'], 422);
        }

        $fileArray = is_array($files) ? $files : [$files];

        $uploaded = [];
        $tag = $request->input('tag');
        $imaginableType = $request->input('imaginable_type');
        $imaginableId = $request->input('imaginable_id');

        foreach ($fileArray as $file) {
            try {
                // store file on disk (creates thumbnail if available)
                $storeResult = ImageService::store($file, $tag ?? 'images', 'public', [
                    'make_thumbnail' => true,
                    'thumbnail' => ['width' => 400, 'height' => 400, 'prefix' => 'thumb_'],
                ]);

                // create DB record (adjust fields to your Image model)
                $image = Image::create([
                    'disk' => 'public',
                    'path' => $storeResult['path'] ?? null,
                    'filename' => $storeResult['filename'] ?? null,
                    'mime' => $storeResult['mime'] ?? $file->getClientMimeType(),
                    'size' => $storeResult['size'] ?? $file->getSize(),
                    'thumbnail_path' => $storeResult['thumbnail'] ?? null,
                    'tag' => $tag ?? null,
                    'imaginable_type' => $imaginableType ?? null,
                    'imaginable_id' => $imaginableId ?? null,
                ]);

                $uploaded[] = $image->toApi();
            } catch (\Throwable $e) {
                Log::warning('Image upload failed: ' . $e->getMessage());
                $uploaded[] = [
                    'error' => true,
                    'message' => $e->getMessage(),
                ];
            }
        }

        // if single file => return single object
        if (count($uploaded) === 1) {
            return response()->json(['success' => true, 'data' => $uploaded[0]], 201);
        }

        return response()->json(['success' => true, 'data' => $uploaded], 201);

    }


    public function storeBase64(Request $request)
    {

        // Validate basic shape
        $validator = Validator::make($request->all(), [
            'image' => 'required|string',               // data URI or raw base64
            'tag' => 'nullable|string|max:100',
            'imageable_type' => 'nullable|string',
            'imageable_id' => 'nullable|integer',
            'max_bytes' => 'nullable|integer',          // optional override for size limit
            'quality' => 'nullable|integer|min:0|max:100' // optional quality override
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payload',
                'errors' => $validator->errors(),
            ], 422);
        }

        $imageData = $request->input('image');
        $tag = $request->input('tag', 'images');
        $imaginableType = $request->input('imageable_type');
        $imaginableId = $request->input('imageable_id');

        // Build options for the service (use sensible defaults; client can override)
        $options = [
            'make_thumbnail' => true,
            'thumbnail' => [
                'width' => 400,
                'height' => 400,
                'prefix' => 'thumb_',
            ],
            'max_bytes' => $request->input('max_bytes', 5 * 1024 * 1024), // default 5MB
            'quality' => $request->input('quality', 85),
        ];

        try {
            // storeBase64 returns: ['path','filename','mime','size','thumbnail' => path|null]
            $storeResult = ImageService::storeBase64($imageData, $tag, 'public', $options);

            // Persist DB record
            $image = Image::create([
                'disk' => 'public',
                'path' => $storeResult['path'] ?? null,
                'filename' => $storeResult['filename'] ?? null,
                'mime' => $storeResult['mime'] ?? null,
                'size' => $storeResult['size'] ?? null,
                'thumbnail_path' => $storeResult['thumbnail'] ?? null,
                'tag' => $tag ?? null,
                'imageable_type' => $imaginableType ?? null,
                'imageable_id' => $imaginableId ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => $image->toApi(),
            ], 201);
        } catch (\Throwable $e) {
            Log::warning('Base64 image upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
