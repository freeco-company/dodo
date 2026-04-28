<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E — ECPay notify callback log (server-to-server PaymentInfo /
 * PeriodReturnURL hits). Idempotent on MerchantTradeNo + RtnCode so a duplicate
 * notify (ECPay retries up to 5 times) won't double-extend a subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecpay_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('merchant_trade_no', 64);
            $table->string('trade_no', 64)->nullable();
            $table->string('rtn_code', 8)->nullable();
            $table->string('rtn_msg', 128)->nullable();
            // 'auth' = first-time authorisation; 'period' = recurring period charge.
            $table->enum('callback_kind', ['auth', 'period', 'other'])->default('other');
            $table->json('raw_payload')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_trade_no', 'rtn_code', 'callback_kind'], 'ecpay_callbacks_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecpay_callbacks');
    }
};
