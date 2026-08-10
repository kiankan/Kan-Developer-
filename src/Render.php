<?php

declare(strict_types=1);

final class Render
{
    public static function auction(array $data): string
    {
        $lines = ["🎯 {$data['name']}", "وضعیت: {$data['status_text']}"];

        if ($data['highest_bid'] !== null) {
            $lines[] = "بالاترین پیشنهاد: {$data['highest_bid']} TON";
        }
        if ($data['bid_step'] !== null) {
            $lines[] = "گام مزایده: {$data['bid_step']} TON";
        }
        if ($data['minimum_bid'] !== null) {
            $lines[] = "حداقل پیشنهاد بعدی: {$data['minimum_bid']} TON";
        }

        if ($data['ends_at'] !== null) {
            $remaining = $data['remaining_seconds'];
            $lines[] = '';
            $lines[] = '⏳ زمان باقی‌مانده: ' . Format::humanizeDuration($remaining) . " ($remaining ثانیه)";
            $lines[] = 'پایان مزایده: ' . Format::formatUtc($data['ends_at']);
        } else {
            $lines[] = '';
            $lines[] = '⏳ شمارش‌معکوس فعالی برای این آیتم پیدا نشد (مزایده تمام شده یا در وضعیت دیگری است).';
        }

        $lines[] = '';
        $lines[] = $data['url'];

        return implode("\n", $lines);
    }

    public static function tx(array $data): string
    {
        $status = ($data['success'] && !$data['aborted']) ? 'موفق ✅' : 'ناموفق ❌';
        $lines = ['🔗 تراکنش TON', "هش: {$data['hash']}", "وضعیت: $status"];

        if ($data['utime'] !== null) {
            $lines[] = 'زمان: ' . Format::formatUtc($data['utime']);
        }
        if ($data['account_address']) {
            $name = $data['account_name'] ? " ({$data['account_name']})" : '';
            $lines[] = "حساب: {$data['account_address']}$name";
        }
        if ($data['exit_code_description']) {
            $lines[] = "نتیجه اجرا: {$data['exit_code_description']}";
        }
        $lines[] = sprintf('کارمزد کل: %.6f TON', $data['total_fees_ton']);

        return implode("\n", $lines);
    }

    public static function wallet(array $data): string
    {
        $lines = ['👛 موجودی ولت', "آدرس: {$data['address']}", ''];
        $lines[] = sprintf('موجودی: %.4f TON', $data['balance_ton']);

        if ($data['toman'] !== null) {
            $lines[] = sprintf('معادل: %s تومان', number_format((int) round($data['toman'])));
        } else {
            $lines[] = 'معادل تومان در دسترس نبود (نرخ لحظه‌ای دریافت نشد).';
        }

        return implode("\n", $lines);
    }
}
