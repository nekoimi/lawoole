<?php
/**
 * nekoimi  2021/11/12 11:55
 */

namespace Lawoole\Utils;


use Illuminate\Support\Str;

final class OsUtils
{
    /**
     * Mac OS
     *
     * @const string
     */
    public const MAC_OS = 'dar';

    /**
     * Linux
     *
     * @const string
     */
    public const LINUX = 'lin';

    /**
     * Linux
     *
     * @const string
     */
    public const WIN = 'win';

    /**
     * Cygwin
     *
     * @const string
     */
    public const CYGWIN = 'cyg';

    /**
     * Returns true if current OS in types
     *
     * @param string ...$types
     *
     * @return bool
     */
    public static function is(string ...$types): bool
    {
        return Str::contains(static::current(), $types);
    }

    /**
     * Current OS
     *
     * @return string
     */
    public static function current(): string
    {
        return Str::substr(Str::lower(PHP_OS), 0, 3);
    }

    /**
     * @return bool
     */
    public static function isWin(): bool {
        return self::is(OsUtils::WIN);
    }

    /**
     * @return bool
     */
    public static function isLinux(): bool {
        return self::is(OsUtils::LINUX);
    }

    /**
     * @return bool
     */
    public static function isMac(): bool {
        return self::is(OsUtils::MAC_OS, OsUtils::CYGWIN);
    }
}
