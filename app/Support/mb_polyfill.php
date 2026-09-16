<?php

/**
 * mbstring 函数补齐（仅当运行环境未加载 ext-mbstring 时生效，有则零影响）。
 *
 * 背景：laravels worker 跑在系统 CLI php8.5 上（未装 mbstring 扩展），
 * Laravel 12（v12.69.2）的 Str.php 仍用到 mb_split / mb_strimwidth，而
 * symfony/polyfill-mbstring 恰好不覆盖这两个函数（mb_split 仅出现在其
 * 文档注释中，无实现），worker 一启动即
 * FatalError: Call to undefined function Illuminate\Support\mb_split()
 * （2026-09-13 生产全线 500 事故根因）。
 *
 * 依赖说明：本文件内的实现用到 mb_substr / mb_strlen / mb_strwidth，
 * 这些由 symfony/polyfill-mbstring（composer 依赖）在无 ext-mbstring 时提供。
 */

if (!function_exists('mb_split')) {
    /**
     * 与 mbregex 语义一致：大小写不敏感、多字节安全的正则切分。
     *
     * @return list<string>|false
     */
    function mb_split(string $pattern, string $string, int $limit = -1): array|false
    {
        return preg_split('~' . str_replace('~', '\~', $pattern) . '~iu', $string, $limit);
    }
}

if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $string, int $start, int $width, string $trim_marker = '', ?string $encoding = null): string
    {
        $encoding = $encoding ?: (function_exists('mb_internal_encoding') ? mb_internal_encoding() : 'UTF-8');
        $string = mb_substr($string, $start, null, $encoding);
        if (mb_strwidth($string, $encoding) <= $width) {
            return $string;
        }
        $width -= mb_strwidth($trim_marker, $encoding);
        $result = '';
        $length = mb_strlen($string, $encoding);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($string, $i, 1, $encoding);
            $width -= mb_strwidth($char, $encoding);
            if ($width < 0) {
                break;
            }
            $result .= $char;
        }

        return $result . $trim_marker;
    }
}
