<?php

namespace App\Filament\Resources\MediaAssetResource\Pages;

use App\Filament\Resources\MediaAssetResource;
use App\Services\Media\MediaSecurityValidator;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CreateMediaAsset extends CreateRecord
{
    protected static string $resource = MediaAssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $filePath = $data['file_path'] ?? null;
        if ($filePath) {
            $absolutePath = Storage::disk('public')->path($filePath);
            if (file_exists($absolutePath)) {
                $validator = app(MediaSecurityValidator::class);
                $sec = $validator->validateFile($absolutePath);

                $data['original_name'] = basename($filePath);
                $data['file_name'] = basename($filePath);
                $data['mime_type'] = $sec['mime'];
                $data['file_size'] = $sec['size'];
                $data['width'] = $sec['width'];
                $data['height'] = $sec['height'];
                $data['sha256'] = $sec['sha256'];
                $data['disk'] = 'public';

                if (empty($data['title'])) {
                    $data['title'] = pathinfo($filePath, PATHINFO_FILENAME);
                }

                if (empty($data['alt_text'])) {
                    $data['alt_text'] = "Hình ảnh " . $data['title'];
                }
            }
        }

        $data['uploaded_by'] = Auth::id();

        return $data;
    }
}
