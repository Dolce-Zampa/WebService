<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use App\Facades\Queue;
use PS\Webservice\Domain\Entities\ProductEntity;
use PS\Webservice\Domain\Models\PS\Orders\Order;
use PS\Webservice\Domain\Models\PS\Orders\OrderReviewMailLog;
use PS\Webservice\Service\MailerInterface;
use PS\Webservice\Service\PS\PrestashopServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:orders:send-review-request',
    description: 'Invia mail di richiesta recensione per gli ordini consegnati (evitando invii duplicati)'
)]
class SendReviewRequestMailCommand extends Command
{
    protected static $defaultName = 'app:orders:send-review-request';
    protected static $defaultDescription = 'Invia mail di richiesta recensione per gli ordini consegnati (evitando invii duplicati)';

    private MailerInterface $mailer;
    private PrestashopServiceInterface $service;

    public function __construct(MailerInterface $mailer, PrestashopServiceInterface $service)
    {
        parent::__construct();
        $this->mailer = $mailer;
        $this->service = $service;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(self::$defaultDescription)
            ->addOption('state', 's', InputOption::VALUE_OPTIONAL, 'ID dello stato ordine consegnato', 5)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Numero massimo di ordini da elaborare', 50)
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Considera ordini degli ultimi N giorni (0 = tutti)', 30)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula l\'esecuzione senza inviare mail né salvare i log');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stateId = (int) $input->getOption('state');
        $limit = (int) $input->getOption('limit');
        $days = (int) $input->getOption('days');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Verifica ordini consegnati e invio mail di richiesta recensione');

        if ($dryRun) {
            $io->note('Esecuzione in modalità DRY-RUN: nessuna email verrà realmente inviata.');
        }

        // Query degli ordini in stato consegnato che non hanno ancora registrato l'invio della mail
        $query = Order::query()
            ->delivered($stateId)
            ->pendingReviewMail()
            ->with(['customer', 'details']);

        if ($days > 0) {
            $fromDate = (new \DateTime())->modify("-{$days} days")->format('Y-m-d H:i:s');
            $query->where('date_add', '>=', $fromDate);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var \Illuminate\Database\Eloquent\Collection $orders */
        $orders = $query->get();

        if ($orders->isEmpty()) {
            $io->info('Nessun ordine consegnato in attesa di invio mail recensione.');
            return Command::SUCCESS;
        }

        $io->text("Trovati {$orders->count()} ordini da elaborare.");

        $processedCount = 0;
        $sentCount = 0;

        foreach ($orders as $order) {
            $processedCount++;

            // Controllo ulteriore di sicurezza per evitare doppi invii
            if (OrderReviewMailLog::where('id_order', $order->id_order)->exists()) {
                $io->warning("Ordine #{$order->id_order} già elaborato in precedenza. Saltato.");
                continue;
            }

            $customer = $order->customer;
            $email = $customer?->email;
            $firstname = $customer?->firstname ?? 'Cliente';

            if (empty($email)) {
                $io->warning("Ordine #{$order->id_order}: Email cliente assente. Saltato.");
                continue;
            }

            // Mappatura dettagli prodotti per la mail
            $products = [];
            foreach ($order->details as $detail) {
                $products[] = [
                    'id_product' => $detail->id_product,
                    'quantity' => $detail->product_quantity ?? 1,
                ];
            }

            if ($dryRun) {
                $io->info("[DRY-RUN] Ordine #{$order->id_order} per {$email} (Prodotti: " . count($products) . ')');
                continue;
            }

            try {
                // Mettiamo in coda l'invio della mail tramite il servizio di coda
                Queue::push('review-request-mail', [
                    'email' => $email,
                    'firstname' => $firstname,
                    'id_order' => (int) $order->id_order,
                    'products' => $products,
                    'id_customer' => $customer->id_customer,
                ]);

                OrderReviewMailLog::create([
                    'id_order' => $order->id_order,
                    'id_customer' => $customer->id_customer,
                    'email' => $email,
                    'sent_at' => new \DateTime(),
                ]);

                $sentCount++;
                $io->success("Mail di richiesta recensione in coda per l'ordine #{$order->id_order} a <{$email}>");
            } catch (\Throwable $e) {
                $io->error("Errore durante l'invio mail per l'ordine #{$order->id_order}: " . $e->getMessage());
            }
        }

        if ($dryRun) {
            $io->success("[DRY-RUN] Completata la simulazione per {$processedCount} ordini.");
        } else {
            $io->success("Completato! Mail inviate: {$sentCount} su {$processedCount} ordini elaborati.");
        }

        return Command::SUCCESS;
    }
}
