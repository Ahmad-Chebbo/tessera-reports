<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    public const PERIODS = [7, 30, 90];

    public const DEFAULT_PERIOD = 30;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Unsupported periods fall back to the default instead of failing the page.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function period(): int
    {
        $period = (int) $this->query('period', self::DEFAULT_PERIOD);

        return in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }
}
