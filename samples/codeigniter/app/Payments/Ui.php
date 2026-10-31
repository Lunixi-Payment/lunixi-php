<?php

declare(strict_types=1);

namespace App\Payments;

final class Ui
{
    public static function text(string $key): string
    {
        $text = lang('Payments.' . $key);
        if (!is_string($text)) {
            throw new \LogicException('A translated UI string is required.');
        }
        return $text;
    }
}
