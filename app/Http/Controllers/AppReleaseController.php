<?php

namespace App\Http\Controllers;

use App\Models\AppRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class AppReleaseController extends Controller
{
    public function index()
    {
        return [
            "data" => AppRelease::get()
        ];
    }

    // Upload endpoint (admin-only) — enforce with middleware or policy
    public function store(Request $request)
    {
        // $this->authorize('uploadRelease'); // implement policy or middleware

        Log::info('AppRelease upload attempt', [
            'platform' => $request->input('platform'),
            'version' => $request->input('version'),
            'build'   => $request->input('build'),
            'file_size' => $request->input('file_size') ?? ($request->hasFile('file') ? $request->file('file')->getSize() : null),
            'uploaded_by' => Auth::id(),
        ]);

        $v = Validator::make($request->all(), [
            'platform' => 'required|in:android,ios',
            'version' => ['required','string','max:32'],
            'build' => 'required|integer|min:1',
            'file_base64' => 'required|string',
            'file_name' => 'required|string',
            'file_mime' => 'sometimes|string',
            // limit to 200MB by default (adjust to your needs)
            'file_size' => 'required|integer|max:209715200',
            'is_published' => 'sometimes|boolean',
            'is_supported' => 'sometimes|boolean',
            'is_forced_update' => 'sometimes|boolean',
            'release_notes' => 'sometimes|string|max:2000',
            'sha256_base64' => 'sometimes|string', // optional client-supplied base64 SHA
        ]);

        if ($v->fails()) {
            return response()->json(['ok' => false, 'errors' => $v->errors()], 422);
        }

        $platform = $request->input('platform');
        $build = (int) $request->input('build');
        $version = $request->input('version');
        $fileName = $request->input('file_name');
        $fileMime = $request->input('file_mime', null);
        $fileSize = (int) $request->input('file_size');
        $fileBase64 = $request->input('file_base64');
        $clientShaBase64 = $request->input('sha256_base64', null);

        // strip data URI prefix if present
        if (strpos($fileBase64, 'base64,') !== false) {
            $fileBase64 = substr($fileBase64, strpos($fileBase64, 'base64,') + 7);
        }

        // decode base64 (strict)
        $decoded = base64_decode($fileBase64, true);
        if ($decoded === false) {
            return response()->json(['ok' => false, 'message' => 'Invalid base64 file data'], 422);
        }

        // optional: verify declared size matches decoded size
        $actualSize = strlen($decoded);
        if ($fileSize > 0 && $actualSize !== $fileSize) {
            Log::warning("Declared file_size ({$fileSize}) differs from decoded size ({$actualSize})");
            // choose to continue, or return error. We continue but log.
        }

        // compute sha256
        $shaRaw = hash('sha256', $decoded, true); // raw binary
        $shaHex = bin2hex($shaRaw);
        $shaBase64 = base64_encode($shaRaw);

        // client checksum verification (if given)
        if ($clientShaBase64 !== null && !hash_equals($shaBase64, $clientShaBase64)) {
            return response()->json(['ok' => false, 'message' => 'Checksum mismatch'], 422);
        }

        $ext = pathinfo($fileName, PATHINFO_EXTENSION) ?: ($platform === 'android' ? 'apk' : 'bin');
        $safeVersion = Str::slug($version);
        $filename = sprintf('%s_%d_%s.%s', $platform, $build, $safeVersion, $ext);
        $pathKey = "releases/{$platform}/{$filename}";

        $disk = 'public';

        // store file on disk
        try {
            $put = Storage::disk($disk)->put($pathKey, $decoded);
        } catch (Exception $e) {
            Log::error('Storage put failed', ['exception' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Failed to store file (exception)'], 500);
        }

        if (!$put) {
            return response()->json(['ok' => false, 'message' => 'Failed to store file'], 500);
        }

        // Persist DB and handle cleanup if DB write fails
        DB::beginTransaction();
        try {
            $release = AppRelease::create([
                'platform' => $platform,
                'version' => $version,
                'build' => $build,
                'file_path' => $pathKey,            // store the storage key/path (NOT the boolean)
                'file_name' => $fileName,
                'file_mime' => $fileMime,
                'file_size' => $actualSize,
                'sha256' => $shaHex,
                'is_published' => (bool)$request->input('is_published', true),
                'is_supported' => (bool)$request->input('is_supported', true),
                'is_forced_update' => (bool)$request->input('is_forced_update', false),
                'release_notes' => $request->input('release_notes'),
                'uploaded_by' => Auth::id(),
            ]);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            // delete stored file to avoid orphaned files
            try {
                Storage::disk($disk)->delete($pathKey);
            } catch (Exception $inner) {
                Log::error('Failed to delete orphaned file after DB failure', ['path' => $pathKey, 'err' => $inner->getMessage()]);
            }
            Log::error('DB create failed for AppRelease', ['exception' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Failed to persist release record'], 500);
        }

        return response()->json(['ok' => true, 'data' => $release], 201);
    }

    // API for clients to check latest release and min supported
    public function latest(Request $request)
    {
        $platform = $request->get('platform', 'android');
        $disk = config('filesystems.default');

        // latest published by build
        $latest = AppRelease::where('platform', $platform)->where('is_published', true)->orderByDesc('build')->first();

        // compute minimum supported build (the smallest build where is_supported = true)
        $minSupported = AppRelease::where('platform', $platform)->where('is_supported', true)->orderBy('build', 'asc')->first();

        if (!$latest) {
            return response()->json(['ok' => true, 'data' => null]);
        }

        // If S3 or other private disks: return temporary/signed URL otherwise public URL
        if ($disk === 's3') {
            $url = Storage::disk($disk)->temporaryUrl($latest->file_path, now()->addMinutes(30));
        } else {
            $url = Storage::disk($disk)->url($latest->file_path);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'latest' => [
                    'id' => $latest->id,
                    'version' => $latest->version,
                    'build' => $latest->build,
                    'release_notes' => $latest->release_notes,
                    'is_forced_update' => (bool)$latest->is_forced_update,
                    'download_url' => $url,
                    'sha256' => $latest->sha256,
                ],
                'min_supported' => $minSupported ? [
                    'version' => $minSupported->version,
                    'build' => $minSupported->build,
                ] : null,
            ],
        ]);
    }

    public function download($id)
    {
        $release = AppRelease::findOrFail($id);

        if (!$release->is_published) {
            abort(403, 'Release not published');
        }

        $disk = config('filesystems.default');
        $path = $release->file_path;

        if (!Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        // increment download count
        $release->increment('download_count');

        // For S3/private: redirect to temporary URL
        if ($disk === 's3') {
            $url = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(10));
            return redirect()->away($url);
        }

        // Local or streaming-capable disk: stream with headers & attachment filename
        $stream = Storage::disk($disk)->readStream($path);
        if ($stream === false) {
            abort(500, 'Failed to read file');
        }

        $filename = $release->file_name ?? basename($path);
        $mime = $release->file_mime ?? Storage::disk($disk)->mimeType($path) ?? 'application/octet-stream';
        $length = Storage::disk($disk)->size($path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => $length,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            // optionally expose sha header so clients can verify easily
            'X-Checksum-Sha256' => $release->sha256,
        ]);
    }
}
