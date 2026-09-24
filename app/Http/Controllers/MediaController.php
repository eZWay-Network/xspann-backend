<?php

namespace App\Http\Controllers;

use App\Services\VideoStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function show(Request $request, VideoStorage $storage, string $path): StreamedResponse
    {
        abort_if(str_contains($path, '..') || str_starts_with($path, '/'), 404);

        $localPath = $storage->localPath($path);

        abort_unless($localPath && is_file($localPath), 404);

        $size = filesize($localPath);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;

        $range = $request->headers->get('Range');

        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            if ($matches[1] === '' && $matches[2] !== '') {
                $suffixLength = (int) $matches[2];
                $start = max(0, $size - $suffixLength);
            } else {
                $start = (int) $matches[1];
            }

            if ($matches[2] !== '') {
                $end = min((int) $matches[2], $end);
            }

            abort_if($start > $end || $start >= $size, 416, '', [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => 'bytes */'.$size,
            ]);

            $status = 206;
        }

        $length = $end - $start + 1;
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=31536000',
            'Content-Disposition' => 'inline',
            'Content-Length' => (string) $length,
            'Content-Type' => File::mimeType($localPath) ?: 'application/octet-stream',
        ];

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($localPath, $start, $length): void {
            $handle = fopen($localPath, 'rb');

            if (! $handle) {
                return;
            }

            try {
                fseek($handle, $start);
                $remaining = $length;
                $chunkSize = 1024 * 1024;

                while ($remaining > 0 && ! feof($handle)) {
                    $chunk = fread($handle, min($chunkSize, $remaining));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $remaining -= strlen($chunk);

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();

                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                fclose($handle);
            }
        }, $status, $headers);
    }
}
