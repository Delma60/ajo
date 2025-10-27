<?php

namespace App\Classes\Service;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageService
{
    protected string $storageDisk = 'public';
    protected string $baseFolder = 'pages'; // default folder; adjust if you use images for other models

    public function __construct(?string $disk = null, ?string $baseFolder = null)
    {
        if ($disk) $this->storageDisk = $disk;
        if ($baseFolder) $this->baseFolder = $baseFolder;
    }

    /**
     * Store an uploaded file and return array with disk path and public url.
     *
     * @param UploadedFile $file
     * @param string|null $subfolder optional subfolder under baseFolder, e.g. 'banners'
     * @return array ['path' => 'pages/abc.jpg', 'url' => '/storage/pages/abc.jpg']
     */
    public static function store(UploadedFile $file, ?string $subfolder = null): array
    {
        $self = new self();

        $folder = $self->baseFolder;
        if ($subfolder) {
            // sanitize subfolder
            $folder = trim("{$folder}/" . trim($subfolder, '/'), '/');
        }

        // store file on configured disk (public)
        $filePath = $file->store($folder, $self->storageDisk);

        // get public url (requires `php artisan storage:link`)
        $url = Storage::disk($self->storageDisk)->url($filePath);

        return [
            'path' => $filePath,
            'url'  => $url,
        ];
    }

    /**
     * Delete a stored file path (path should be the disk path: e.g. pages/xxx.jpg).
     */
    public static function delete(?string $path): void
    {
        if (! $path) return;

        $self = new self();
        Storage::disk($self->storageDisk)->delete($path);
    }


    /**
 * Store an image provided as base64 (data URI or raw base64).
 *
 * @param string $base64        Data URI ("data:image/png;base64,...") or raw base64.
 * @param string $tag           Folder/tag to store under (e.g. "images")
 * @param string $disk          Storage disk (e.g. "public")
 * @param array  $options       [
 *                                 'make_thumbnail' => true,
 *                                 'thumbnail' => ['width' => 400, 'height' => 400, 'prefix' => 'thumb_'],
 *                                 'max_bytes' => 5 * 1024 * 1024, // optional maximum raw bytes
 *                                 'quality' => 85, // jpeg/webp quality 0-100
 *                              ]
 * @return array ['path','filename','mime','size','thumbnail' => thumbnail_path|null]
 * @throws \Exception
 */
    public static function storeBase64(string $base64, string $tag = 'images', string $disk = 'public', array $options = [])
    {
        $options = array_merge([
            'make_thumbnail' => true,
            'thumbnail' => ['width' => 400, 'height' => 400, 'prefix' => 'thumb_'],
            'max_bytes' => 5 * 1024 * 1024,
            'quality' => 85,
        ], $options);

        // 1) Normalize: strip data URI prefix if present
        if (preg_match('/^data:(image\/[a-zA-Z]+);base64,/', $base64, $m)) {
            $base64body = substr($base64, strpos($base64, ',') + 1);
        } else {
            // assume raw base64
            $base64body = $base64;
        }

        // 2) Decode
        $data = base64_decode($base64body, true);
        if ($data === false) {
            throw new \Exception('Invalid base64 data or decode failed.');
        }

        // 3) Optional size limit check (raw bytes)
        if (isset($options['max_bytes']) && is_int($options['max_bytes']) && strlen($data) > $options['max_bytes']) {
            throw new \Exception('Image exceeds maximum allowed size.');
        }

        // 4) Detect MIME & extension using getimagesizefromstring
        $imgInfo = @getimagesizefromstring($data);
        if ($imgInfo === false) {
            throw new \Exception('Decoded data is not a valid image.');
        }
        $mime = $imgInfo['mime'] ?? null;
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp'
        ];
        if (!$mime || !array_key_exists($mime, $allowedMimes)) {
            throw new \Exception('Unsupported image MIME type: ' . ($mime ?? 'unknown'));
        }
        $ext = $allowedMimes[$mime];

        // 5) filename & paths
        $filename = \Illuminate\Support\Str::random(20) . '.' . $ext;
        $folder = rtrim($tag, '/');
        $path = $folder . '/' . $filename;

        // 6) Store original bytes
        \Illuminate\Support\Facades\Storage::disk($disk)->put($path, $data);

        $result = [
            'path' => $path,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($data),
            'thumbnail' => null,
        ];

        // 7) Create thumbnail using GD if requested
        if (!empty($options['make_thumbnail'])) {
            $thumbConf = $options['thumbnail'] ?? [];
            $thumbPrefix = $thumbConf['prefix'] ?? 'thumb_';
            $thumbW = $thumbConf['width'] ?? 400;
            $thumbH = $thumbConf['height'] ?? 400;
            $quality = (int)($options['quality'] ?? 85);

            // create image resource from string
            $srcImg = @imagecreatefromstring($data);
            if ($srcImg !== false) {
                try {
                    $origW = imagesx($srcImg);
                    $origH = imagesy($srcImg);

                    // calculate target size preserving aspect ratio
                    $ratio = min($thumbW / $origW, $thumbH / $origH, 1);
                    $targetW = (int)round($origW * $ratio);
                    $targetH = (int)round($origH * $ratio);

                    // create destination image
                    $dstImg = imagecreatetruecolor($targetW, $targetH);

                    // preserve transparency for PNG/GIF/WebP
                    if (in_array($ext, ['png', 'gif', 'webp'])) {
                        // Enable saving of alpha channel
                        imagealphablending($dstImg, false);
                        imagesavealpha($dstImg, true);
                        $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
                        imagefilledrectangle($dstImg, 0, 0, $targetW, $targetH, $transparent);
                    } else {
                        // fill with white background for JPEG/BMP
                        $white = imagecolorallocate($dstImg, 255, 255, 255);
                        imagefilledrectangle($dstImg, 0, 0, $targetW, $targetH, $white);
                    }

                    // resample
                    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);

                    // capture output to variable
                    ob_start();
                    switch ($ext) {
                        case 'jpg':
                            imagejpeg($dstImg, null, $quality);
                            break;
                        case 'png':
                            // quality for PNG is 0 (no compression) to 9, convert from 0-100
                            $pngLevel = (int)round((9 * (100 - $quality)) / 100);
                            imagepng($dstImg, null, $pngLevel);
                            break;
                        case 'gif':
                            imagegif($dstImg);
                            break;
                        case 'webp':
                            if (function_exists('imagewebp')) {
                                imagewebp($dstImg, null, $quality);
                            } else {
                                // fallback to jpeg if webp unsupported
                                imagejpeg($dstImg, null, $quality);
                            }
                            break;
                        case 'bmp':
                            // GD may not support BMP output; fallback to jpeg
                            imagejpeg($dstImg, null, $quality);
                            break;
                        default:
                            imagejpeg($dstImg, null, $quality);
                            break;
                    }
                    $thumbData = ob_get_clean();

                    // clean up resources
                    imagedestroy($dstImg);
                    imagedestroy($srcImg);

                    if ($thumbData !== false && $thumbData !== '') {
                        $thumbFilename = $thumbPrefix . $filename;
                        $thumbPath = $folder . '/' . $thumbFilename;
                        \Illuminate\Support\Facades\Storage::disk($disk)->put($thumbPath, $thumbData);
                        $result['thumbnail'] = $thumbPath;
                    }
                } catch (\Throwable $e) {
                    // swallow thumbnail errors; do not fail entire upload
                }
            }
        }

        return $result;
    }

}
