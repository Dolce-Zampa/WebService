<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use Illuminate\Database\Capsule\Manager;
use PS\Webservice\Service\Financial\SellerSaleNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:financial:notify-seller-sale', description: 'Invia le notifiche di vendita mancanti per un carrello pagato')]
final class SendSellerSaleNotificationCommand extends Command
{
    public function __construct(private readonly Manager $db, private readonly SellerSaleNotificationService $notifications)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('cart-id', InputArgument::REQUIRED, 'ID del carrello');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cartId = filter_var($input->getArgument('cart-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($cartId === false) {
            $io->error('Il carrello deve essere un numero intero positivo.');
            return Command::INVALID;
        }

        $orders = $this->db->table('orders as o')
            ->leftJoin('order_state as s', 's.id_order_state', '=', 'o.current_state')
            ->where('o.id_cart', $cartId)
            ->get(['o.id_order', 'o.reference', 's.paid']);
        if ($orders->count() !== 1 || (int) $orders[0]->paid !== 1) {
            $io->error('Serve un solo ordine per il carrello, in uno stato pagato. Verifica ordine e pagamento prima di procedere.');
            return Command::FAILURE;
        }
        $order = $orders[0];
        $sellers = $this->db->table('order_detail as d')
            ->leftJoin('product as p', 'p.id_product', '=', 'd.product_id')
            ->leftJoin('manufacturer as m', 'm.id_manufacturer', '=', 'p.id_manufacturer')
            ->where('d.id_order', $order->id_order)
            ->distinct()->get(['m.id_manufacturer', 'm.name', 'm.email']);
        if ($sellers->isEmpty()) {
            $io->error('Nessun prodotto trovato per questo ordine.');
            return Command::FAILURE;
        }
        foreach ($sellers as $seller) {
            if ((int) $seller->id_manufacturer < 1 || !filter_var(trim((string) $seller->email), FILTER_VALIDATE_EMAIL)) {
                $io->error('Un prodotto non ha un artigiano con indirizzo email valido.');
                return Command::FAILURE;
            }
        }

        $before = $this->db->table('seller_sale_notification_logs')->where('id_order', $order->id_order)
            ->pluck('status', 'id_manufacturer')->all();
        $this->notifications->notifyFinalizedSalesForCart($cartId);
        $after = $this->db->table('seller_sale_notification_logs')->where('id_order', $order->id_order)
            ->pluck('status', 'id_manufacturer')->all();

        $failed = false;
        foreach ($sellers as $seller) {
            $id = (int) $seller->id_manufacturer;
            $status = $after[$id] ?? 'non registrata';
            $result = $status === 'sent' && ($before[$id] ?? null) === 'sent' ? 'già inviata' : ($status === 'sent' ? 'inviata' : $status);
            $io->writeln(sprintf('Ordine %s — %s (ID %d): %s', $order->reference, $seller->name, $id, $result));
            $failed = $failed || $status !== 'sent';
        }
        if ($failed) {
            $io->error('Almeno una notifica non risulta inviata. Controlla il registro e i log applicativi.');
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
