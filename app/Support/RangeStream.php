<?php

namespace App\Support;

use Psr\Http\Message\StreamInterface;

/** Read-only window over a local media file; the response emitter reads in chunks. */
class RangeStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private mixed $handle, private int $offset, private int $length)
    {
        $this->seek(0);
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function detach(): mixed
    {
        $handle = $this->handle;
        $this->handle = null;

        return $handle;
    }

    public function getSize(): ?int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return !is_resource($this->handle) || $this->position >= $this->length;
    }

    public function isSeekable(): bool
    {
        return is_resource($this->handle);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function isReadable(): bool
    {
        return is_resource($this->handle);
    }


    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $position = match ($whence) {
            SEEK_SET => $offset, SEEK_CUR => $this->position + $offset, SEEK_END => $this->length + $offset,
            default => throw new \RuntimeException('Invalid seek origin.'),
        };
        if (!$this->isSeekable() || $position < 0 || $position > $this->length || fseek($this->handle, $this->offset + $position) !== 0) {
            throw new \RuntimeException('Cannot seek media.');
        }
        $this->position = $position;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Read-only media.');
    }


    public function read(int $length): string
    {
        if (!$this->isReadable() || $length < 0) {
            throw new \RuntimeException('Cannot read media.');
        }
        $length = min($length, $this->length - $this->position);
        if ($length === 0) {
            return '';
        }
        $data = fread($this->handle, $length);
        if ($data === false || $data === '') {
            throw new \RuntimeException('Incomplete media read.');
        }
        $this->position += strlen($data);

        return $data;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $data = is_resource($this->handle) ? stream_get_meta_data($this->handle) : [];

        return $key === null ? $data : ($data[$key] ?? null);
    }
}
