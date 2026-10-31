<?php

namespace App\Http\Controllers;

abstract class Controller
{
    protected function privateJson(array $data, int $status = 200): \Spark\Http\Response
    {
        return json($data, $status)->setHeader('Cache-Control', 'no-store');
    }
}
