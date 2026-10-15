<?php

declare(strict_types=1);

namespace BrewPHP\Http;

/**
 * A file uploaded with the request.
 *
 *     $avatar = $request->file('avatar');
 *     if ($avatar?->isValid()) {
 *         $avatar->moveTo(base_path('media/avatars'), $avatar->hashName());
 *     }
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $clientName,
        public readonly string $clientMimeType = 'application/octet-stream',
        public readonly int $error = UPLOAD_ERR_OK,
        public readonly int $size = 0,
        private readonly bool $test = false,
    ) {
    }

    /**
     * Build a fake upload from an existing file (for tests).
     */
    public static function fake(string $path, ?string $clientName = null, string $mimeType = 'application/octet-stream'): self
    {
        return new self($path, $clientName ?? basename($path), $mimeType, UPLOAD_ERR_OK, (int) @filesize($path), test: true);
    }

    /**
     * Turn PHP's `$_FILES` array into UploadedFile objects, keeping the
     * shape of nested field names such as `photos[]`.
     *
     * @param array<mixed> $files
     * @return array<string, UploadedFile|array<mixed>>
     */
    public static function normalize(array $files): array
    {
        $normalized = [];
        foreach ($files as $field => $spec) {
            if (is_array($spec) && isset($spec['tmp_name'])) {
                $normalized[(string) $field] = self::fromSpec($spec);
            }
        }

        return $normalized;
    }

    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->test ? is_file($this->path) : is_uploaded_file($this->path);
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the file.',
            default => 'The upload was stopped.',
        };
    }

    /**
     * The extension from the client's file name, lower-case.
     */
    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    /**
     * The MIME type detected from the file's contents. Prefer this over the
     * client-supplied type, which can be anything.
     */
    public function mimeType(): string
    {
        if (function_exists('finfo_open') && is_file($this->path)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $type = finfo_file($finfo, $this->path);
                finfo_close($finfo);
                if (is_string($type)) {
                    return $type;
                }
            }
        }

        return 'application/octet-stream';
    }

    /**
     * A random file name that keeps the extension, safe to store. Extensions
     * a web server could run (php, phtml, phar, cgi...) are dropped.
     */
    public function hashName(): string
    {
        $extension = (string) preg_replace('/[^a-z0-9]/', '', strtolower($this->clientExtension()));
        // Never keep an extension a web server might execute.
        if (preg_match('/^(php\d*|phtml|pht|phar|phps|inc|cgi|pl|py|rb|sh|asp|aspx|jsp|exe|shtml|htaccess)$/', $extension) === 1) {
            $extension = '';
        }

        return bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    }

    public function content(): string
    {
        return (string) file_get_contents($this->path);
    }

    /**
     * Move the file into $directory and return its new path.
     */
    public function moveTo(string $directory, ?string $name = null): string
    {
        if (!$this->isValid()) {
            throw new \RuntimeException('Cannot move an invalid upload: ' . ($this->errorMessage() ?: 'not an uploaded file.'));
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create directory "%s".', $directory));
        }

        $name = basename($name ?? $this->hashName());
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        $moved = $this->test ? rename($this->path, $target) : move_uploaded_file($this->path, $target);
        if (!$moved) {
            throw new \RuntimeException(sprintf('Could not move the upload to "%s".', $target));
        }

        return $target;
    }

    /**
     * @param array<mixed> $spec
     * @return UploadedFile|array<mixed>
     */
    private static function fromSpec(array $spec): UploadedFile|array
    {
        if (!is_array($spec['tmp_name'])) {
            return new self(
                (string) $spec['tmp_name'],
                (string) ($spec['name'] ?? ''),
                (string) ($spec['type'] ?? 'application/octet-stream'),
                (int) ($spec['error'] ?? UPLOAD_ERR_NO_FILE),
                (int) ($spec['size'] ?? 0),
            );
        }

        $files = [];
        foreach (array_keys($spec['tmp_name']) as $key) {
            $files[$key] = self::fromSpec([
                'tmp_name' => $spec['tmp_name'][$key],
                'name' => $spec['name'][$key] ?? '',
                'type' => $spec['type'][$key] ?? '',
                'error' => $spec['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                'size' => $spec['size'][$key] ?? 0,
            ]);
        }

        return $files;
    }
}
