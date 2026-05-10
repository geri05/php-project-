<?php
/*
 * pricing.php — Calculates the parking fee based on duration.
 * Rates: 150 L/hour (up to 5h), 800 L/day (6–24h or per day), 12000 L/month (30+ days).
 */

const PRICE_PER_HOUR  = 150;
const PRICE_PER_DAY   = 800;
const PRICE_PER_MONTH = 12000;

function calculate_fee(int $duration_seconds): array
{
    if ($duration_seconds < 60) $duration_seconds = 60;

    $total_minutes = (int) ceil($duration_seconds / 60);
    $total_hours   = (int) ceil($duration_seconds / 3600);
    $total_days    = (int) ceil($duration_seconds / 86400);
    $total_months  = (int) ceil($duration_seconds / (86400 * 30));

    $amount = 0.0;
    $plan   = 'hourly';
    $detail = '';

    if ($duration_seconds <= 5 * 3600) {
        $amount = $total_hours * PRICE_PER_HOUR;
        $plan   = 'hourly';
        $detail = $total_hours . ' hr × ' . PRICE_PER_HOUR . ' L';
    } elseif ($duration_seconds <= 24 * 3600) {
        $amount = PRICE_PER_DAY;
        $plan   = 'daily';
        $detail = '1 day (Pro plan)';
    } elseif ($duration_seconds <= 30 * 86400) {
        $amount = $total_days * PRICE_PER_DAY;
        $plan   = 'daily';
        $detail = $total_days . ' days × ' . PRICE_PER_DAY . ' L';
    } else {
        $months_full = (int) floor($duration_seconds / (86400 * 30));
        $remainder   = $duration_seconds - ($months_full * 86400 * 30);
        $extra_days  = (int) ceil($remainder / 86400);

        $amount = ($months_full * PRICE_PER_MONTH) + ($extra_days * PRICE_PER_DAY);
        $plan   = 'monthly';
        $detail = "$months_full months × " . PRICE_PER_MONTH . " L"
                . ($extra_days > 0 ? " + $extra_days days × " . PRICE_PER_DAY . " L" : "");
    }

    return [
        'amount'     => round($amount, 2),
        'plan'       => $plan,
        'detail'     => $detail,
        'minutes'    => $total_minutes,
        'hours'      => $total_hours,
        'duration_s' => $duration_seconds,
    ];
}