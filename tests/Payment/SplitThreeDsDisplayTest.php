<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Payment;

use Lunixi\Sdk\Payment\SplitCompletionOutcome;
use Lunixi\Sdk\Payment\SplitPayErrorCode;
use Lunixi\Sdk\Payment\SplitPaymentResult;
use Lunixi\Sdk\Payment\ThreeDsDisplay;
use PHPUnit\Framework\TestCase;

/**
 * 3D Secure display (form setting), the continuation page's
 * result (`completion`) and its error codes. Same values as the Node SDK.
 */
final class SplitThreeDsDisplayTest extends TestCase
{
    public function testThreeDsDisplayHasExactlyTheTwoGatewayValues(): void
    {
        $this->assertSame(
            ['INLINE' => 'INLINE', 'REDIRECT' => 'REDIRECT'],
            (new \ReflectionClass(ThreeDsDisplay::class))->getConstants()
        );
    }

    public function testCompletionOutcomeMatchesTheCallbackStatusValues(): void
    {
        $this->assertSame(
            ['SUCCESS' => 'success', 'FAILURE' => 'failure', 'PENDING' => 'pending'],
            (new \ReflectionClass(SplitCompletionOutcome::class))->getConstants()
        );
    }

    public function testContinuationPageAndRestartErrorCodes(): void
    {
        $this->assertSame('split_pay.resume.unavailable', SplitPayErrorCode::RESUME_UNAVAILABLE);
        $this->assertSame('split_pay.resume.already_opened', SplitPayErrorCode::RESUME_ALREADY_OPENED);
        $this->assertSame('split_pay.resume.proof_invalid', SplitPayErrorCode::RESUME_PROOF_INVALID);
        $this->assertSame('split_pay.resume.not_configured', SplitPayErrorCode::RESUME_NOT_CONFIGURED);
        $this->assertSame('split_pay.three_ds.restart_exhausted', SplitPayErrorCode::THREE_DS_RESTART_EXHAUSTED);
        $this->assertSame('split_pay.three_ds.restart_expired', SplitPayErrorCode::THREE_DS_RESTART_EXPIRED);
        $codes = array_values((new \ReflectionClass(SplitPayErrorCode::class))->getConstants());
        $this->assertSame($codes, array_values(array_unique($codes)));
    }

    public function testFormAnswerCarriesTheDisplayAndNoCompletionWhileStepsRemain(): void
    {
        $result = new SplitPaymentResult(['code' => 'AWAITING_3DS', 'data' => [
            'status' => 'AWAITING_3DS',
            'threeDsDisplay' => 'REDIRECT',
            'completion' => null,
        ]]);

        $this->assertSame(ThreeDsDisplay::REDIRECT, $result->threeDsDisplay());
        $this->assertNull($result->completion());
    }

    public function testFormAnswerCarriesTheMainPaymentId(): void
    {
        $result = new SplitPaymentResult(['code' => 'AWAITING_3DS', 'data' => [
            'paymentId' => 'pay_main',
            'status' => 'AWAITING_3DS',
        ]]);

        $this->assertSame('pay_main', $result->paymentId());
        $this->assertNull((new SplitPaymentResult(['data' => ['splitPaymentId' => 'sp_1']]))->paymentId());
        $this->assertNull((new SplitPaymentResult(['data' => ['paymentId' => '']]))->paymentId());
    }

    public function testUnknownOrMissingDisplayIsNull(): void
    {
        $this->assertNull((new SplitPaymentResult(['data' => []]))->threeDsDisplay());
        $this->assertNull((new SplitPaymentResult(['data' => ['threeDsDisplay' => 'POPUP']]))->threeDsDisplay());
        $this->assertNull((new SplitPaymentResult(['data' => ['threeDsDisplay' => 'redirect']]))->threeDsDisplay());
        $this->assertNull((new SplitPaymentResult([]))->completion());
    }

    public function testConcludedAnswerCarriesTheCompletionAndTheSignedCallback(): void
    {
        $completion = [
            'outcome' => SplitCompletionOutcome::SUCCESS,
            'code' => 'COMPLETED',
            'message' => 'Ödeme tamamlandı.',
            'paymentId' => 'pay_main',
            'orderId' => 'order-1',
            'merchantCallback' => [
                'url' => 'https://shop.example/return',
                'method' => 'POST',
                'fields' => [
                    'paymentId' => 'pay_main',
                    'orderId' => 'order-1',
                    'status' => 'success',
                    'success' => 'true',
                    'code' => 'COMPLETED',
                    'message' => 'Ödeme tamamlandı.',
                    '_timestamp' => '2026-10-19T10:00:00.000Z',
                    '_signature' => 'v2=abc',
                ],
            ],
        ];
        $result = new SplitPaymentResult(['code' => 'COMPLETED', 'data' => [
            'status' => 'COMPLETED',
            'threeDsDisplay' => 'INLINE',
            'completion' => $completion,
        ]]);

        $this->assertSame(ThreeDsDisplay::INLINE, $result->threeDsDisplay());
        $this->assertSame($completion, $result->completion());
        $this->assertTrue($result->isCompleted());
    }
}
