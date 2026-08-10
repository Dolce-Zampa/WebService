<?php
namespace PS\Webservice\Service\AWS;

use Psr\Http\Message\UploadedFileInterface;

class S3ManagerService
{
    private \Aws\S3\S3Client $s3Client;
    private string $bucket;

    public function __construct(\Aws\S3\S3Client $s3Client, string $bucket)
    {
        $this->s3Client = $s3Client;
        $this->bucket = $bucket;
    }

    public function uploadFile(string $key, string $filePath): bool
    {
        try {
            $this->s3Client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'SourceFile' => $filePath,
            ]);
            return true;
        } catch (\Aws\Exception\AwsException $e) {
            // Log the error or handle it as needed
            return false;
        }
    }

    public function deleteFile(string $key): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            return true;
        } catch (\Aws\Exception\AwsException $e) {
            // Log the error or handle it as needed
            return false;
        }
    }

    public function uploadAvatarToS3(UploadedFileInterface $uploadedFile, string $fileName): string
    {
        $prefix = 'img/avatars';
        $cleanFileName = ltrim($fileName, '/');
        $key = $prefix . '/' . $cleanFileName;
        
        $stream = $uploadedFile->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $putObjectPayload = [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $stream,
            'ContentType' => $uploadedFile->getClientMediaType() ?: 'application/octet-stream',
        ];

        $result = $this->s3Client->putObject($putObjectPayload);
        
        // Il risultato di putObject include sempre ObjectURL in versioni recenti
        return $result['ObjectURL'] ?? $this->s3Client->getObjectUrl($this->bucket, $key);
    }
}