<?php

declare(strict_types=1);

use PS\Webservice\Commands\ReconcileStripeFeesCommand;
use PS\Webservice\Http\Controller\StripeWebhookController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileStripeFeesCommandTest extends TestCase
{
    public function test_dry_run_pages_through_charges_without_applying_changes(): void
    {
        $controller = $this->createMock(StripeWebhookController::class);
        $controller->expects(self::exactly(2))->method('reconcileStripeCharge')
            ->willReturnCallback(function (\Stripe\StripeObject $charge, ?string $account, bool $dryRun): string {
                self::assertTrue($dryRun);
                self::assertSame('acct_123', $account);
                return 'ready';
            });
        $calls = [];
        $list = function (array $params, ?string $account) use (&$calls): array {
            $calls[] = $params;
            return count($calls) === 1
                ? ['data' => [\Stripe\Charge::constructFrom(['id' => 'ch_1'])], 'has_more' => true]
                : ['data' => [\Stripe\Charge::constructFrom(['id' => 'ch_2'])], 'has_more' => false];
        };
        $tester = new CommandTester(new ReconcileStripeFeesCommand($controller, $list));
        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => '2026-01-01', '--to' => '2026-02-01', '--account' => 'acct_123']));
        self::assertSame('ch_1', $calls[1]['starting_after']);
        self::assertSame(strtotime('2026-01-01 UTC'), $calls[0]['created']['gte']);
        self::assertSame(strtotime('2026-02-01 UTC'), $calls[0]['created']['lt']);
        self::assertStringContainsString('pronte=2', $tester->getDisplay());
    }

    public function test_apply_fails_if_charge_needs_retry(): void
    {
        $controller = $this->createMock(StripeWebhookController::class);
        $controller->expects(self::once())->method('reconcileStripeCharge')->willReturnCallback(
            function (\Stripe\StripeObject $charge, ?string $account, bool $dryRun): string {
                self::assertFalse($dryRun);
                return 'pending';
            },
        );
        $list = fn () => ['data' => [\Stripe\Charge::constructFrom(['id' => 'ch_1'])], 'has_more' => false];
        $tester = new CommandTester(new ReconcileStripeFeesCommand($controller, $list));
        self::assertSame(Command::FAILURE, $tester->execute(['--from' => '2026-01-01', '--to' => '2026-02-01', '--apply' => true]));
        self::assertStringContainsString('in attesa=1', $tester->getDisplay());
        self::assertStringContainsString('Charge ch_1: balance transaction non ancora disponibile.', $tester->getDisplay());
    }

    public function test_failed_charge_is_identified_without_leaking_exception_details(): void
    {
        $controller = $this->createMock(StripeWebhookController::class);
        $controller->method('reconcileStripeCharge')->willThrowException(new RuntimeException('sensitive details'));
        $list = fn () => ['data' => [\Stripe\Charge::constructFrom(['id' => 'ch_2'])], 'has_more' => false];
        $tester = new CommandTester(new ReconcileStripeFeesCommand($controller, $list));
        self::assertSame(Command::FAILURE, $tester->execute(['--from' => '2026-01-01', '--to' => '2026-02-01']));
        self::assertStringContainsString('Charge ch_2: riconciliazione non riuscita.', $tester->getDisplay());
        self::assertStringNotContainsString('sensitive details', $tester->getDisplay());
    }

    public function test_incomplete_pagination_is_an_error(): void
    {
        $controller = $this->createMock(StripeWebhookController::class);
        $tester = new CommandTester(new ReconcileStripeFeesCommand($controller, fn () => ['data' => [], 'has_more' => true]));
        self::assertSame(Command::FAILURE, $tester->execute(['--from' => '2026-01-01', '--to' => '2026-02-01']));
    }
}
