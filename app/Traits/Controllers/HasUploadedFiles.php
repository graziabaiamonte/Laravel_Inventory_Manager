<?php

namespace App\Traits\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// use Illuminate\Support\Facades\Storage;

trait HasUploadedFiles
{
    public function saveUploadedFile(Request $request, Model $model, string $collection = ''): void
    {

        $this->syncDeletedFile($request, $model, $collection);

        if ($request->file('media_upload')) {

            foreach ($request->file('media_upload') as $file) {
                $model
                    ->addMedia($file)
                    ->toMediaCollection($collection);
            }

        } elseif ($request->filled('discogs_image_url')) {

            $this->saveDiscogsImage($request->discogs_image_url, $model, $collection);

        }

    }

    protected function saveDiscogsImage(string $imageUrl, Model $model, string $collection = ''): void
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'header' => "User-Agent: Mozilla/5.0 (compatible; LaravelApp/1.0)\r\n",
                ],
            ]);

            // Download image from Discogs
            $imageContents = file_get_contents($imageUrl, false, $context);

            if ($imageContents !== false) {
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $originalFilename = basename($urlPath);

                $extension = '';
                if ($originalFilename) {
                    $pathInfo = pathinfo($originalFilename);
                    $extension = $pathInfo['extension'] ?? '';
                }

                if (empty($extension)) {
                    $imageInfo = getimagesizefromstring($imageContents);
                    if ($imageInfo) {
                        $extension = match ($imageInfo[2]) {
                            IMAGETYPE_JPEG => 'jpg',
                            IMAGETYPE_PNG => 'png',
                            IMAGETYPE_GIF => 'gif',
                            IMAGETYPE_WEBP => 'webp',
                            default => 'jpg'
                        };
                    } else {
                        $extension = 'jpg';
                    }
                }

                $filename = ($originalFilename && ! empty(pathinfo($originalFilename, PATHINFO_FILENAME)))
                    ? $originalFilename
                    : 'discogs_image_'.time().'.'.$extension;

                $tempFile = tempnam(sys_get_temp_dir(), 'discogs_image');
                file_put_contents($tempFile, $imageContents);

                $model->addMedia($tempFile)
                    ->usingName('Discogs Cover Image')
                    ->usingFileName($filename)
                    ->toMediaCollection($collection);

                unlink($tempFile);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the record creation
            Log::channel('discogs')->warning('Failed to save Discogs image: '.$e->getMessage(), [
                'image_url' => $imageUrl,
                'model_type' => get_class($model),
                'model_id' => $model->id ?? 'new',
            ]);
        }
    }

    protected function syncDeletedFile(Request $request, Model $model, string $collection = ''): void
    {

        $media = $model->getMedia($collection);
        $uploads = collect($request->input('media'))->map(fn ($media) => $media['id'])->toArray();

        if ($media) {
            foreach ($media as $file) {
                if (empty($uploads) || ! in_array($file->id, $uploads)) {
                    $file->delete();
                }
            }
        }

    }
}
