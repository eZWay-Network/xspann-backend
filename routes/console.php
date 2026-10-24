<?php

use App\Services\VideoRetry;
use App\Jobs\PruneUploads;
use Spark\Console\Prompt;

command('greet', function (Prompt $prompt) {
    $name = $prompt->ask('What is your name?');
    $prompt->message("Hello, {$name}!", 'success');
})->description('Show a Greeting Message');

command('videos:retry', function (Prompt $prompt, array $args) {
    $id = null;
    if (array_key_exists('id', $args)) {
        $id = is_string($args['id'])
            ? filter_var($args['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if ($id === false) {
            throw new InvalidArgumentException('The --id option must be a positive video ID.');
        }
    }

    $prompt->message(
        VideoRetry::retry($id) ?
        'Failed videos have been queued for retry.' : 'No failed or interrupted videos found.'
    );
})->description('Retry failed or interrupted videos, optionally using --id');

command('uploads:prune', function (Prompt $prompt) {
    PruneUploads::dispatch();
    $prompt->message('Abandoned upload cleanup queued.');
})->description('Queue cleanup of expired, unreferenced uploads and chunk sessions');
