<?php
declare(strict_types=1);

namespace AstrX\Http;

use AstrX\Http\Diagnostic\MoveFailedDiagnostic;
use AstrX\Http\Diagnostic\NotAnUploadedFileDiagnostic;
use AstrX\Http\Diagnostic\UploadErrorDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

final class UploadedFile
{
    public const string ID_UPLOAD_ERROR         = 'astrx.http/upload_error';
    public const DiagnosticLevel LVL_UPLOAD_ERROR = DiagnosticLevel::ERROR;

    public const string ID_NOT_AN_UPLOADED_FILE         = 'astrx.http/not_an_uploaded_file';
    public const DiagnosticLevel LVL_NOT_AN_UPLOADED_FILE = DiagnosticLevel::ERROR;

    public const string ID_MOVE_FAILED         = 'astrx.http/move_failed';
    public const DiagnosticLevel LVL_MOVE_FAILED = DiagnosticLevel::ERROR;

    public function __construct(
        private readonly string $clientFilename,
        private readonly string $clientMediaType,
        private readonly string $tempPath,
        private readonly int $size,
        private readonly int $error,
    ) {}

    public function clientFilename(): string  { return $this->clientFilename; }
    public function clientMediaType(): string { return $this->clientMediaType; }
    public function tempPath(): string        { return $this->tempPath; }
    public function size(): int               { return $this->size; }
    public function error(): int              { return $this->error; }
    public function isValid(): bool           { return $this->error === UPLOAD_ERR_OK; }
    public function hasError(): bool          { return !$this->isValid(); }

    /** @return Result<bool> */
    public function moveTo(string $path): Result
    {
        if ($this->hasError()) {
            return Result::err(false, Diagnostics::of(
                new UploadErrorDiagnostic(
                    self::ID_UPLOAD_ERROR,
                    self::LVL_UPLOAD_ERROR,
                    $this->clientFilename,
                    $this->error,
                )
            ));
        }

        if (!is_uploaded_file($this->tempPath)) {
            return Result::err(false, Diagnostics::of(
                new NotAnUploadedFileDiagnostic(
                    self::ID_NOT_AN_UPLOADED_FILE,
                    self::LVL_NOT_AN_UPLOADED_FILE,
                    $this->tempPath,
                )
            ));
        }

        if (!move_uploaded_file($this->tempPath, $path)) {
            return Result::err(false, Diagnostics::of(
                new MoveFailedDiagnostic(
                    self::ID_MOVE_FAILED,
                    self::LVL_MOVE_FAILED,
                    $path,
                )
            ));
        }

        return Result::ok(true);
    }
}