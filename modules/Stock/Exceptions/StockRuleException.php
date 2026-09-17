<?php

namespace Modules\Stock\Exceptions;

use RuntimeException;

/**
 * 业务规则异常：库存不足、状态不允许变更等操作被拒。
 *
 * 与系统错误（SQL 异常等）区分开——Service 层抛它表示「业务上不允许」，
 * 控制器据此返回 422；未捕获的其他异常由 ExceptionHandler 按 500 处理。
 */
class StockRuleException extends RuntimeException {}
