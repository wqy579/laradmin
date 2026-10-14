<?php

namespace Modules\Ledger\Services;

/**
 * 金额转人民币中文大写。
 *
 * 用于凭证合计行「人民币（大写）壹仟贰佰叁拾肆元伍角陆分」。
 */
class AmountChinese
{
    private const DIGITS = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];

    private const UNITS = ['', '拾', '佰', '仟'];

    private const BIG_UNITS = ['', '万', '亿'];

    public static function convert(float|string|int $amount): string
    {
        // 负数：红字冲销允许负金额，大写带「负」
        $negative = false;
        $amount = (float) $amount;
        if ($amount < 0) {
            $negative = true;
            $amount = abs($amount);
        }

        if ($amount == 0) {
            return '零元整';
        }

        // 拆分整数与小数部分
        $str = number_format($amount, 2, '.', '');
        [$intPart, $fracPart] = explode('.', $str);
        $intPart = ltrim($intPart, '0') ?: '0';
        $jiao = (int) $fracPart[0];
        $fen = (int) $fracPart[1];

        $intChinese = self::intToChinese($intPart);

        $result = $intChinese.'元';

        if ($jiao === 0 && $fen === 0) {
            $result .= '整';
        } else {
            if ($jiao > 0) {
                $result .= self::DIGITS[$jiao].'角';
            } elseif ($fen > 0) {
                $result .= '零';
            }
            if ($fen > 0) {
                $result .= self::DIGITS[$fen].'分';
            }
        }

        return $negative ? '负'.$result : $result;
    }

    private static function intToChinese(string $intPart): string
    {
        if ($intPart === '0') {
            return '零';
        }

        // 按 4 位一组分组（万、亿）
        $groups = [];
        $len = strlen($intPart);
        for ($i = $len; $i > 0; $i -= 4) {
            $groups[] = substr($intPart, max(0, $i - 4), $i - max(0, $i - 4));
        }
        $groups = array_reverse($groups);

        $result = '';
        $groupCount = count($groups);
        foreach ($groups as $idx => $group) {
            $bigIdx = $groupCount - 1 - $idx;
            $groupStr = self::groupToChinese(str_pad($group, 4, '0', STR_PAD_LEFT));
            if ($groupStr !== '') {
                $result .= $groupStr.self::BIG_UNITS[$bigIdx];
            }
        }

        // 合并连续零、去尾零
        $result = preg_replace('/零+/', '零', $result);
        $result = rtrim($result, '零');
        if ($result === '') {
            $result = '零';
        }

        return $result;
    }

    private static function groupToChinese(string $group): string
    {
        $result = '';
        $len = strlen($group);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) $group[$i];
            $unitIdx = $len - 1 - $i;
            if ($digit === 0) {
                $result .= '零';
            } else {
                $result .= self::DIGITS[$digit].self::UNITS[$unitIdx];
            }
        }
        $result = preg_replace('/零+/', '零', $result);
        $result = rtrim($result, '零');

        return $result;
    }
}
