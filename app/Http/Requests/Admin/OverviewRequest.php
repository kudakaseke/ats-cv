<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->role === 'super_admin';
    }

    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']];
    }
}
