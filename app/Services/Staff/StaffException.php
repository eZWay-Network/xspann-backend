<?php

namespace App\Services\Staff;

final class StaffException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $field = 'form')
    {
        parent::__construct($message);
    }
}
