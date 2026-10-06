<?php

use App\Services\VideoRetry;
use App\Jobs\PruneUploads;
use Spark\Console\Prompt;

command('greet', function (Prompt $prompt) {
    $name = $prompt->ask('What is your name?');
    $prompt->message("Hello, {$name}!", 'success');
})->description('Show a Greeting Message');

command('videos:retry', function (Prompt $prompt, array $args) {
    $msg = VideoRetry::retry() ?
        'Failed videos have been queued for retry.' : 'No failed or interrupted videos found.';

    $prompt->message($msg);
})->description('Retry processing for one failed or interrupted videos');

command('uploads:prune', function (Prompt $prompt) {
    PruneUploads::dispatch();

    $prompt->message('Abandoned upload cleanup queued.');
})->description('Queue cleanup of expired, unreferenced uploads and chunk sessions');
