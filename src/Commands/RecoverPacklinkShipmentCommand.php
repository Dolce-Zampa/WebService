<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use GuzzleHttp\ClientInterface;
use Illuminate\Database\ConnectionInterface;
use PS\Webservice\Service\Shipping\PacklinkClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:packlink:recover', description: 'Recover an ambiguous Packlink shipment or interrupted synchronization')]
final class RecoverPacklinkShipmentCommand extends Command
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ClientInterface $http)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('order-id', InputArgument::REQUIRED, 'PrestaShop order ID')
            ->addOption('worker-stopped', null, InputOption::VALUE_NONE, 'Confirm all shipping workers have been stopped')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Existing Packlink reference, verified against the provider')
            ->addOption('confirm-no-draft', null, InputOption::VALUE_NONE, 'After checking Packlink, allow a new creation attempt')
            ->addOption('reset-sync', null, InputOption::VALUE_NONE, 'Reset an interrupted synchronization')
            ->addOption('retry-pending', null, InputOption::VALUE_NONE, 'Retry pending preparation now')
            ->addOption('retry-sync', null, InputOption::VALUE_NONE, 'Retry a failed synchronization now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawId = (string) $input->getArgument('order-id');
        $reference = $input->getOption('reference');
        $retry = (bool) $input->getOption('confirm-no-draft');
        $reset = (bool) $input->getOption('reset-sync');
        $retryPending = (bool) $input->getOption('retry-pending');
        $retrySync = (bool) $input->getOption('retry-sync');
        if (!ctype_digit($rawId) || (int) $rawId <= 0 || !$input->getOption('worker-stopped')
            || ((int) ($reference !== null) + (int) $retry + (int) $reset + (int) $retryPending + (int) $retrySync) !== 1) {
            $output->writeln('<error>Stop all shipping workers and choose exactly one recovery action.</error>');
            return Command::FAILURE;
        }
        $id = (int) $rawId;
        try {
            if ($retryPending) {
                $updated = $this->db->table('packlink_shipments')->where('order_id', $id)->where('status', 'pending')
                    ->update(['last_error' => null, 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            } elseif ($retrySync) {
                $updated = $this->db->table('packlink_shipments')->where('order_id', $id)->where('status', 'created')->where('sync_state', 'idle')
                    ->update(['sync_due_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            } elseif ($reset) {
                $updated = $this->db->table('packlink_shipments')->where('order_id', $id)->where('status', 'created')
                    ->where('sync_state', 'running')->update(['sync_state' => 'idle', 'sync_due_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            } elseif ($retry) {
                $updated = $this->db->table('packlink_shipments')->where('order_id', $id)->whereIn('status', ['unknown', 'creating'])
                    ->update(['status' => 'pending', 'last_error' => null, 'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            } else {
                if (!is_string($reference) || $reference === '' || strlen($reference) > 191) {
                    throw new \InvalidArgumentException('Invalid reference.');
                }
                $client = new PacklinkClient($this->http, (string) env('PACKLINK_API_URL', 'https://api.packlink.com'), (string) env('PACKLINK_API_KEY', ''));
                $shipment = $client->shipment($reference);
                if (($shipment['packlink_reference'] ?? null) !== $reference || ($shipment['shipment_custom_reference'] ?? null) !== 'order-' . $id) {
                    throw new \RuntimeException('Reference does not match the order.');
                }
                $updated = $this->db->table('packlink_shipments')->where('order_id', $id)->whereIn('status', ['unknown', 'creating'])
                    ->update(['status' => 'created', 'provider_reference' => $reference, 'last_error' => null, 'sync_due_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            }
            if ($updated !== 1) {
                $output->writeln('<error>No matching operation was recoverable.</error>');
                return Command::FAILURE;
            }
        } catch (\Throwable $exception) {
            $output->writeln('<error>Recovery failed. Check the configuration and Packlink association.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Recovery recorded.');
        return Command::SUCCESS;
    }
}
