<?php
namespace PS\Webservice\Traits;

use Psr\Http\Message\UploadedFileInterface;

trait FileResource {

    public function uploadFile(string $filePath, string $destinationPath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        if (!is_dir(dirname($destinationPath))) {
            mkdir(dirname($destinationPath), 0755, true);
        }

        if (!copy($filePath, $destinationPath)) {
            throw new \RuntimeException("Failed to copy file to destination: {$destinationPath}");
        }

        return $destinationPath;
    }

    public function uploadUploadedFile(UploadedFileInterface $uploadedFile, string $destinationDirectory, ?string $fileName = null): string
    {
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Failed to upload file');
        }

        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0755, true);
        }

        $clientFilename = (string) $uploadedFile->getClientFilename();
        $finalFileName = $fileName ?? ($clientFilename !== '' ? $clientFilename : 'upload');
        $destinationPath = rtrim($destinationDirectory, '/') . '/' . $finalFileName;

        $uploadedFile->moveTo($destinationPath);

        return $destinationPath;
    }
}