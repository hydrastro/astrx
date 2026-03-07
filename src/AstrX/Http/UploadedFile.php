<?php
declare(strict_types=1);

namespace AstrX\Http;

use RuntimeException;
// TODO: customize throw
final class UploadedFile
{
    public function __construct(
        private readonly string $clientFilename,
        private readonly string $clientMediaType,
        private readonly string $tempPath,
        private readonly int $size,
        private readonly int $error,
    ) {
    }

    public function clientFilename(): string
    {
        return $this->clientFilename;
    }

    public function clientMediaType(): string
    {
        return $this->clientMediaType;
    }

    public function tempPath(): string
    {
        return $this->tempPath;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function error(): int
    {
        return $this->error;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    public function hasError(): bool
    {
        return !$this->isValid();
    }

    public function moveTo(string $path): void
    {
        if ($this->hasError()) {
            throw new RuntimeException(sprintf(
                                           'Cannot move uploaded file "%s": upload error code %d.',
                                           $this->clientFilename,
                                           $this->error,
                                       ));
        }

        if (!is_uploaded_file($this->tempPath)) {
            throw new RuntimeException(sprintf(
                                           'File "%s" is not a valid uploaded file.',
                                           $this->tempPath,
                                       ));
        }

        if (!move_uploaded_file($this->tempPath, $path)) {
            throw new RuntimeException(sprintf(
                                           'Failed to move uploaded file to "%s".',
                                           $path,
                                       ));
        }
    }
}
