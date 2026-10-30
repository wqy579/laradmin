<?php

namespace Modules\Mall\Services;

use Modules\Auth\Models\User as AdminUser;
use Modules\Mall\Models\SmsRecord;
use Modules\Mall\Models\SmsTemplate;

/**
 * F5 短信发送内核 —— 加固后的可观测 + 频控 + 重试治理。
 *
 * 职责（对齐 F5-HARDENING 第 2 项）：
 *  - 频控：同一手机号分钟级/日级上限，超限抛 DomainException（明确报错，不静默丢弃）；
 *  - 重试：明确最大重试次数与退避，禁止无限重发；
 *  - 可观测：每笔落 elapsed_ms（耗时）、request_summary / response_summary（请求/响应摘要）、
 *    error_message（失败原因）。
 *
 * 发送内核仍为「模拟网关」（无真实服务商配置），但状态机与记录结构真实，
 * 供后续替换为真实短信服务商内核时只改 dispatch() 一处。
 */
class SmsSendService
{
    /** 最大重试次数（超过则拒绝，禁止无限重发） */
    public const MAX_RETRY = 3;

    /** 重试退避：距上次发送（同手机号）不足该秒数则拒绝 */
    public const RETRY_BACKOFF_SECONDS = 60;

    /** 频控：同一手机号每分钟发送上限 */
    public const MINUTE_CAP = 5;

    /** 频控：同一手机号每日发送上限 */
    public const DAILY_CAP = 50;

    /**
     * 发送短信：渲染 → 频控 → 落记录（含可观测列）→ 模拟网关置成功。
     *
     * @throws \DomainException 模板缺失/停用、缺变量、频控超限
     */
    public function send(array $payload, int $operatorId): SmsRecord
    {
        $template = SmsTemplate::findOrFail((int) $payload['template_id']);
        if ($template->status !== 1) {
            throw new \DomainException('该模板已停用');
        }

        $content = $template->render($payload['variables'] ?? []);
        $this->assertWithinRateLimit($payload['mobile']);

        return $this->dispatch($template, $payload, $operatorId, 0);
    }

    /**
     * 失败重发：复制原记录重新走发送，受重试次数与退避约束。
     *
     * @throws \DomainException 模板缺失/停用、缺变量、重试超限、退避期、频控超限
     */
    public function resend(SmsRecord $original, int $operatorId): SmsRecord
    {
        if ($original->retry_count >= self::MAX_RETRY) {
            throw new \DomainException('该记录已重试 '.$original->retry_count.' 次，达到上限 '.self::MAX_RETRY.' 次，不再重发');
        }

        $template = SmsTemplate::find($original->template_id);
        if (! $template || $template->status !== 1) {
            throw new \DomainException('模板不存在或已停用，无法重发');
        }

        $content = $template->render($original->variables ?? []);

        // 退避仅约束「失败重试」：成功重发是操作员主动再通知（如换号/补发），
        // 不属于自动重试循环，不适用退避；失败重试若在退避期内则拒绝，防止打爆网关。
        if ($original->status === 'failed') {
            $last = SmsRecord::where('mobile', $original->mobile)
                ->orderByDesc('id')
                ->first();
            if ($last && $last->created_at && $last->created_at->diffInSeconds(now()) < self::RETRY_BACKOFF_SECONDS) {
                throw new \DomainException('距上次发送不足 '.self::RETRY_BACKOFF_SECONDS.' 秒，请稍后重试');
            }
        }

        $this->assertWithinRateLimit($original->mobile);

        return $this->dispatch($template, [
            'mobile' => $original->mobile,
            'receiver_name' => $original->receiver_name,
            'variables' => $original->variables ?? [],
        ], $operatorId, (int) $original->retry_count + 1);
    }

    /**
     * 频控校验：同一手机号分钟/日级上限。
     *
     * @throws \DomainException 超限
     */
    public function assertWithinRateLimit(string $mobile): void
    {
        $sinceMinute = now()->subMinute();
        $sinceDay = now()->startOfDay();

        $minuteCount = SmsRecord::where('mobile', $mobile)->where('created_at', '>=', $sinceMinute)->count();
        if ($minuteCount >= self::MINUTE_CAP) {
            throw new \DomainException('该手机号 1 分钟内发送已达 '.self::MINUTE_CAP.' 次上限，请稍后重试');
        }

        $dayCount = SmsRecord::where('mobile', $mobile)->where('created_at', '>=', $sinceDay)->count();
        if ($dayCount >= self::DAILY_CAP) {
            throw new \DomainException('该手机号今日发送已达 '.self::DAILY_CAP.' 次上限');
        }
    }

    /**
     * 派发记录：渲染后落库 + 模拟网关，记录可观测列。
     */
    private function dispatch(SmsTemplate $template, array $payload, int $operatorId, int $retryCount): SmsRecord
    {
        $start = microtime(true);

        $operator = AdminUser::find($operatorId);
        $record = SmsRecord::create([
            'send_no' => $this->nextSendNo(),
            'template_id' => $template->id,
            'template_code' => $template->code,
            'template_name' => $template->name,
            'mobile' => $payload['mobile'],
            'receiver_name' => $payload['receiver_name'] ?? null,
            'content' => $template->render($payload['variables'] ?? []),
            'variables' => $payload['variables'] ?? null,
            'status' => 'pending',
            'retry_count' => $retryCount,
            'request_summary' => $this->requestSummary($template, $payload),
            'created_by' => $operatorId,
            'creator_name' => $operator?->real_name ?: $operator?->username,
        ]);

        // —— 模拟网关内核：置成功；接入真实服务商时仅替换此处 ——
        // （状态机真实：pending/success/failed，失败走 error_message 落原因。）
        try {
            // 网关调用点（占位）：真实实现在此返回响应摘要，失败抛 GatewayException。
            $response = ['code' => 0, 'message' => 'OK'];
            $record->update([
                'status' => 'success',
                'sent_at' => now(),
                'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                'response_summary' => $this->responseSummary('success', $record->send_no, $response),
            ]);
        } catch (\Throwable $e) {
            $record->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                'response_summary' => $this->responseSummary('failed', $record->send_no, ['message' => $e->getMessage()]),
            ]);
            throw $e;
        }

        return $record->fresh();
    }

    private function requestSummary(SmsTemplate $template, array $payload): string
    {
        return json_encode([
            'template_code' => $template->code,
            'mobile' => $payload['mobile'],
            'receiver_name' => $payload['receiver_name'] ?? null,
            'variable_count' => count($payload['variables'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function responseSummary(string $status, string $sendNo, array $response): string
    {
        return json_encode([
            'status' => $status,
            'send_no' => $sendNo,
            'gateway' => $response,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 生成发送编号 SMS+YmdHis+4位 */
    private function nextSendNo(): string
    {
        do {
            $no = 'SMS'.date('YmdHis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (SmsRecord::where('send_no', $no)->exists());

        return $no;
    }
}
