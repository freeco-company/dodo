<?php

namespace App\Services\Ecpay;

/**
 * Phase E — DTO describing a periodic-charge (定期定額) subscription order
 * to send to ECPay AioCheckOut. We deliberately keep this as a flat value
 * object instead of a fluent builder; ECPay's parameter list is stable and
 * well-documented (https://developers.ecpay.com.tw/?p=2865).
 */
final class SubscriptionRequest
{
    public function __construct(
        public readonly string $merchantTradeNo,   // unique per-order, ≤20 chars
        public readonly int $totalAmount,          // NTD integer
        public readonly string $tradeDesc,
        public readonly string $itemName,
        public readonly string $returnUrl,         // server-to-server notify
        public readonly string $clientBackUrl,     // user-facing redirect
        public readonly string $periodType,        // 'D'|'M'|'Y'
        public readonly int $frequency,            // every N periodType units
        public readonly int $execTimes,            // total charges (0 = forever)
        public readonly ?string $periodReturnUrl = null,  // per-period s2s
    ) {}

    /**
     * Translate to ECPay's expected param array (subset; full set documented
     * at the ECPay portal). Caller still needs to add CheckMacValue.
     *
     * @return array<string, string|int>
     */
    public function toParams(): array
    {
        return [
            'MerchantTradeNo' => $this->merchantTradeNo,
            'MerchantTradeDate' => date('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            'TotalAmount' => $this->totalAmount,
            'TradeDesc' => $this->tradeDesc,
            'ItemName' => $this->itemName,
            'ReturnURL' => $this->returnUrl,
            'ClientBackURL' => $this->clientBackUrl,
            'ChoosePayment' => 'Credit',
            'PeriodAmount' => $this->totalAmount,
            'PeriodType' => $this->periodType,
            'Frequency' => $this->frequency,
            'ExecTimes' => $this->execTimes,
            'PeriodReturnURL' => $this->periodReturnUrl ?? $this->returnUrl,
            'EncryptType' => 1, // SHA-256
        ];
    }
}
