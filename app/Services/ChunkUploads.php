<?php

namespace App\Services;

use Spark\Facades\Lock;
use Spark\Utils\File;
use Spark\Foundation\Exceptions\ValidationException;
use function in_array;

class ChunkUploads
{
    public const MAX_BYTES = 512000 * 1024;
    public const MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public static function rules(bool $chunk = false): array
    {
        $rules = [
            'upload_id' => ['required', 'uuid'],
            'total_chunks' => ['required', 'integer', 'regex:/^[0-9]+$/', 'min:1', 'max:10000'],
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', ['in' => self::MIME_TYPES]],
            'total_size' => ['required', 'integer', 'regex:/^[0-9]+$/', 'min:1', 'max:' . self::MAX_BYTES],
        ];

        if ($chunk) {
            return [
                ...$rules,
                'chunk_index' => ['required', 'numeric', 'regex:/^[0-9]+$/', 'min:0', 'max:9999'],
                'chunk' => ['required', 'file', 'max:2048']
            ];
        }

        return $rules;
    }

    public function store(int $userId, array $data, array $file): void
    {
        $data['chunk_index'] = (int) $data['chunk_index'];

        abort_if($data['chunk_index'] >= $data['total_chunks'], 422, 'Chunk index is outside the upload range.');

        $this->locked($userId, $data['upload_id'], function (string $directory) use ($data, $file) {
            if (!File::ensureDirectoryExists($directory)) {
                throw new \RuntimeException('Cannot create upload directory.');
            }

            $this->manifest($directory, $data, true);

            $size = 0;
            foreach (glob("$directory/*.part") ?: [] as $part) {
                if (basename($part) !== $data['chunk_index'] . '.part') {
                    $size += filesize($part);
                }
            }

            if ($size + filesize($file['tmp_name']) > (int) $data['total_size']) {
                throw ValidationException::withMessages(['total_size' => ['Chunks exceed the declared video size.']]);
            }

            if (!move_uploaded_file($file['tmp_name'], "$directory/" . $data['chunk_index'] . '.part')) {
                throw new \RuntimeException('Cannot store upload chunk.');
            }

            if (!touch("$directory/manifest.json")) {
                throw new \RuntimeException('Cannot update upload activity.');
            }
        });
    }

    public function complete(int $userId, array $data): string
    {
        return $this->locked($userId, $data['upload_id'], function (string $directory) use ($userId, $data) {
            $this->manifest($directory, $data, false);

            $assembledPath = "$directory/assembled.tmp";
            $assembled = fopen($assembledPath, 'wb');
            if (!$assembled) {
                throw new \RuntimeException('Cannot assemble upload.');
            }

            try {
                try {
                    $size = 0;

                    for ($index = 0; $index < (int) $data['total_chunks']; $index++) {
                        $part = "$directory/$index.part";

                        if (!File::isFile($part)) {
                            throw ValidationException::withMessages(['chunk' => ["Missing video chunk $index."]]);
                        }

                        $size += File::size($part);
                        if ($size > (int) $data['total_size']) {
                            throw ValidationException::withMessages(['total_size' => ['Uploaded video size does not match the selected file.']]);
                        }

                        $handle = fopen($part, 'rb');
                        if ($handle === false) {
                            throw new \RuntimeException('Cannot read upload chunk.');
                        }
                        try {
                            if (stream_copy_to_stream($handle, $assembled) !== File::size($part)) {
                                throw new \RuntimeException('Cannot write the complete upload chunk.');
                            }
                        } finally {
                            fclose($handle);
                        }
                    }

                    if ($size !== (int) $data['total_size']) {
                        throw ValidationException::withMessages(['total_size' => ['Uploaded video size does not match the selected file.']]);
                    }

                    if (!fflush($assembled)) {
                        throw new \RuntimeException('Cannot flush the assembled upload.');
                    }
                } finally {
                    fclose($assembled);
                }

                if (!in_array(File::mimeType($assembledPath), self::MIME_TYPES, true)) {
                    throw ValidationException::withMessages(['file' => ['The uploaded file must be a supported video.']]);
                }

                $path = StorageService::pathFor($userId, $data['filename']);
                $path = StorageService::disk()->putFileAs(dirname($path), $assembledPath, basename($path));

                PendingUploads::track($userId, $path);

                File::deleteDirectory($directory);

                return $path;
            } finally {
                File::delete($assembledPath);
            }
        });
    }

    public function prune(int $cutoff): void
    {
        foreach (glob(temp_dir('upload-chunks/*/*'), GLOB_ONLYDIR) ?: [] as $directory) {
            $userId = basename(dirname($directory));
            $uploadId = basename($directory);
            if (!ctype_digit($userId) || !preg_match('/^[a-f0-9-]{36}$/Di', $uploadId)) {
                continue;
            }

            $this->locked((int) $userId, $uploadId, function (string $directory) use ($cutoff): void {
                if (!is_dir($directory)) {
                    return;
                }

                $manifest = "$directory/manifest.json";
                clearstatcache(true, $manifest);
                $modified = is_file($manifest) ? filemtime($manifest) : filemtime($directory);
                if ($modified !== false && $modified < $cutoff && !File::deleteDirectory($directory)) {
                    throw new \RuntimeException('Cannot remove expired upload chunks.');
                }
            });
        }
    }

    private function manifest(string $directory, array $data, bool $create): void
    {
        $manifest = array_intersect_key($data, array_flip(['filename', 'content_type', 'total_size', 'total_chunks']));
        $path = "$directory/manifest.json";

        $manifest['total_size'] = (int) $manifest['total_size'];
        $manifest['total_chunks'] = (int) $manifest['total_chunks'];

        if (File::isFile($path)) {
            if (json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR) !== $manifest) {
                throw ValidationException::withMessages(['upload_id' => ['Upload metadata does not match this session.']]);
            }
        } elseif ($create) {
            if (File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write upload manifest.');
            }
        } else {
            throw ValidationException::withMessages(['upload_id' => ['Upload session was not found.']]);
        }
    }

    private function locked(int $userId, string $uploadId, callable $callback): mixed
    {
        // Validate here too so direct service use cannot construct arbitrary filesystem paths.
        if (!preg_match('/^[a-f0-9-]{36}$/Di', $uploadId)) {
            throw new \InvalidArgumentException('Invalid upload ID.');
        }

        return Lock::withLock("uploads.$userId.$uploadId", fn() => $callback(temp_dir("upload-chunks/$userId/$uploadId")), timeout: 600, waitTimeout: 10);
    }
}
