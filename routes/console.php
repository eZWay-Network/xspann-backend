<?php

use App\Services\VideoRetry;
use App\Jobs\PruneUploads;
use Spark\Console\Prompt;

command('greet', function (Prompt $prompt) {
    $name = $prompt->ask('What is your name?');
    $prompt->message("Hello, {$name}!", 'success');
})->description('Show a Greeting Message');

command('videos:retry', function (Prompt $prompt, array $args) {
    $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if (!$id) {
        $prompt->message('Usage: php spark videos:retry --id=123', 'error');
        return;
    }

    $retried = VideoRetry::retry($id);
    $prompt->message($retried ? 'Video processing queued.' : 'Only failed or processing videos can be retried.');
})->description('Retry processing for one failed or interrupted video');

command('uploads:prune', function (Prompt $prompt) {
    PruneUploads::dispatchOnce()->send();
    $prompt->message('Abandoned upload cleanup queued.');
})->description('Queue cleanup of expired, unreferenced uploads and chunk sessions');
