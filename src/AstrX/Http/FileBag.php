<?php
declare(strict_types=1);

namespace AstrX\Http;


final class FileBag
{
    /** @var array<string, UploadedFile|array<string,mixed>> */
    private array $files = [];

    /** @param array<string, UploadedFile|array<string,mixed>> $files */
    public function __construct(array $files = [])
    {
        $this->replace($files);
    }

    /** @return array<string, UploadedFile|array<string,mixed>> */
    public function all(): array { return $this->files; }

    public function has(string $key): bool                         { return array_key_exists($key, $this->files); }
    /** @return UploadedFile|array<string,mixed>|null */
    public function get(string $key): UploadedFile|array|null      { return $this->files[$key] ?? null; }
    /** @param UploadedFile|array<string,mixed> $file */
    public function set(string $key, UploadedFile|array $file): void { $this->files[$key] = $file; }
    public function remove(string $key): void                      { unset($this->files[$key]); }
    public function clear(): void                                  { $this->files = []; }

    /** @param array<string, UploadedFile|array<string,mixed>> $files */
    public function replace(array $files): void
    {
        foreach ($files as $key => $file) {
        }

        $this->files = $files;
    }
}
