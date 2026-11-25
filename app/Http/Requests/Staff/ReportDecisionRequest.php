<?php

namespace App\Http\Requests\Staff;

final class ReportDecisionRequest extends ModerationActionRequest
{
    public function rules(): array
    {
        return parent::rules() + ['version' => 'required|integer|min:1'];
    }
}
