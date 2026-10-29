<?php

namespace App\Services;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Spark\Facades\DB;
use Spark\Foundation\Exceptions\ValidationException;
use function is_array;
use function is_string;

class PolicyEnrollment
{
    public static function validate(array $input): void
    {
        $value = $input['date_of_birth'] ?? null;
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        if (!$date || $date->format('Y-m-d') !== $value || $date > $today || $date->diff($today)->y < config('policies.minimum_age', 18) || $date->diff($today)->y > 120) {
            throw ValidationException::withMessages(['date_of_birth' => ['A valid date of birth and an age of at least 18 are required.']]);
        }

        self::validatePolicies($input['accepted_policies'] ?? null, true);
    }

    public static function validatePolicies(mixed $accepted, bool $required = false): void
    {
        $documents = config('policies.documents');

        if (!is_array($accepted) || !$accepted) {
            throw ValidationException::withMessages(['accepted_policies' => ['Supply the policy names and versions you accept.']]);
        }

        foreach ($accepted as $policy => $version) {
            if (!isset($documents[$policy]) || $version !== $documents[$policy]['version']) {
                throw ValidationException::withMessages(['accepted_policies' => ['A policy name or version is invalid.']]);
            }
        }

        foreach ($documents as $policy => $document) {
            if ($required && $document['required'] && !isset($accepted[$policy])) {
                throw ValidationException::withMessages(['accepted_policies' => ['Accept the current terms, privacy and community policies.']]);
            }
        }
    }

    public static function complete(User $user, array $input, string $source): void
    {
        self::validate($input);

        DB::transaction(function () use ($user, $input, $source): void {
            if (!$user->update(['date_of_birth' => $input['date_of_birth'], 'onboarded_at' => now()->toDateTimeString()])) {
                throw new \RuntimeException('Enrollment could not be saved.');
            }

            self::accept($user, $input['accepted_policies'], $source);
        });
    }

    public static function accept(User $user, array $accepted, string $source = 'api'): void
    {
        self::validatePolicies($accepted);

        foreach ($accepted as $policy => $version) {
            DB::table('policy_acceptances')->upsert([
                'user_id' => $user->id,
                'policy' => $policy,
                'version' => $version,
                'source' => $source,
                'accepted_at' => now()->toDateTimeString(),
            ], ['user_id', 'policy', 'version'], ['version']);
        }
    }
}
