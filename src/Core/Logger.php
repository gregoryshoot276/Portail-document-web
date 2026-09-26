<?php
declare(strict_types=1);

namespace JC\Core;

final class Logger
{
    public static function write(string $channel, string $level, string $message): void
    {
        $line = sprintf("[%s] %s.%s %s\n", date('Y-m-d H:i:s'), $channel, $level, str_replace(["\r", "\n"], ' ', $message));
        @file_put_contents(App::path('storage/logs/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log'), $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $channel, string $message): void
    {
        self::write($channel, 'info', $message);
    }

    public static function error(string $channel, string $message): void
    {
        self::write($channel, 'error', $message);
    }
}
