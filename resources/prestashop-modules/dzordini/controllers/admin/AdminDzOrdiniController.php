<?php

if (!defined('_PS_VERSION_')) exit;
require_once dirname(__DIR__, 2) . '/classes/DzOrderMarginRepository.php';

class AdminDzOrdiniController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    private function shops()
    {
        return array_values(array_filter(Shop::getContextListShopID(), function ($id) {
            return $this->context->employee->hasAuthOnShop((int) $id);
        }));
    }

    private function repository()
    {
        return new DzOrderMarginRepository(Db::getInstance());
    }

    private function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('dzSaveFee')) {
            if (!$this->access('edit') || !$this->checkToken() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->errors[] = 'Non autorizzato.';
                return;
            }
            try {
                if (!$this->repository()->saveFee((int) Tools::getValue('id_order'), $this->shops(), Tools::getValue('fee_override', ''))) {
                    throw new RuntimeException('Ordine non disponibile.');
                }
                Tools::redirectAdmin($this->context->link->getAdminLink('AdminDzOrdini') . '&saved=1');
            } catch (InvalidArgumentException $exception) {
                $this->errors[] = 'Inserisci un costo non negativo con massimo due decimali.';
            } catch (Exception $exception) {
                PrestaShopLogger::addLog('Errore salvataggio costo ordine: ' . $exception->getMessage(), 3);
                $this->errors[] = 'Impossibile salvare il costo di pagamento.';
            }
            return;
        }
        if (Tools::isSubmit('dzExport')) {
            if (!$this->viewAccess() || !$this->checkToken()) {
                $this->errors[] = 'Non autorizzato.';
                return;
            }
            try {
                $this->exportCsv($this->repository()->orders($this->shops(), (int) $this->context->language->id));
            } catch (Exception $exception) {
                PrestaShopLogger::addLog('Errore esportazione margini ordini: ' . $exception->getMessage(), 3);
                $this->errors[] = 'Impossibile esportare gli ordini.';
            }
            return;
        }
        parent::postProcess();
    }

    private function exportCsv(array $orders)
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="margini_ordini_dolcezampa.csv"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['ID', 'Riferimento', 'Data ordine', 'Stato', 'Pagamento', 'Valuta', 'Totale ordine', 'Spedizione cliente', 'Prodotti', 'Quota artigiano', 'Costo artigiano', 'Costo pagamento', 'Fonte costo pagamento', 'Commissione DolceZampa', 'Margine parziale', 'Verifica'], ';', '"', '');
        foreach ($orders as $order) {
            $c = $order['calculated'];
            $decimal = static function ($n) { return $n === null ? '' : str_replace('.', ',', (string) $n); };
            $text = static function ($s) { return DzOrderMargin::csvText($s); };
            fputcsv($out, [(int) $order['id_order'], $text($order['reference']), $text($order['date_add']), $text($order['state_name'] ?? ''), $text($order['payment']), $text($order['currency']), $decimal($order['total_paid_tax_incl']), $decimal($order['total_shipping_tax_incl']), $decimal($c['products']), $text(implode(' / ', $c['rates'])), $decimal($c['artisan']), $decimal($order['fee']), $text($order['fee_source']), $decimal($c['commission']), $decimal($c['margin']), $text(implode('; ', $c['note']))], ';', '"', '');
        }
        fclose($out);
        exit;
    }

    public function initContent()
    {
        parent::initContent();
        $url = $this->context->link->getAdminLink('AdminDzOrdini');
        $html = '<div class="panel"><h2>Margini ordini DolceZampa</h2>'
            . '<p>Dal 1° maggio 2026. Il margine esclude corriere, imposte e contributi. La spedizione pagata dal cliente è separata dai prodotti. Le aliquote artigiano sono quelle attuali, non storiche.</p>'
            . '<p>Quando mancano dati, sono presenti rimborsi o gli sconti non sono riconciliabili, il margine resta vuoto. CSV: valori statici, aggiornare e riesportare dopo modifiche.</p>'
            . '<p><a class="btn btn-primary" href="' . $this->esc($url . '&dzExport=1') . '">Esporta CSV</a></p>';
        if (Tools::getValue('saved')) $html .= '<div class="alert alert-success">Costo di pagamento salvato.</div>';
        try {
            $orders = $this->repository()->orders($this->shops(), (int) $this->context->language->id);
            $html .= '<div class="table-responsive"><table class="table"><thead><tr><th>Ordine</th><th>Data</th><th>Stato</th><th>Totale</th><th>Sped. cliente</th><th>Prodotti</th><th>Quota artigiano</th><th>Costo artigiano</th><th>Costo pagamento</th><th>Commissione DZ</th><th>Margine parziale</th><th>Verifica</th></tr></thead><tbody>';
            foreach ($orders as $order) {
                $c = $order['calculated'];
                $e = function ($v) { return $this->esc($v === null ? '—' : $v); };
                $feeForm = $e($order['fee']) . ' (' . $e($order['fee_source']) . ')';
                if ($this->access('edit')) {
                    $feeForm .= '<form method="post" action="' . $this->esc($url) . '"><input type="hidden" name="id_order" value="' . (int) $order['id_order'] . '"><input type="text" name="fee_override" value="' . $this->esc($order['fee_override'] ?? '') . '" placeholder="costo manuale" size="9"><button class="btn btn-default btn-xs" type="submit" name="dzSaveFee" value="1">Salva</button></form>';
                }
                $html .= '<tr><td>' . (int) $order['id_order'] . ' ' . $e($order['reference']) . '</td><td>' . $e($order['date_add']) . '</td><td>' . $e($order['state_name']) . '</td><td>' . $e($order['total_paid_tax_incl']) . ' ' . $e($order['currency']) . '</td><td>' . $e($order['total_shipping_tax_incl']) . '</td><td>' . $e($c['products']) . '</td><td>' . $e(implode(' / ', $c['rates'])) . '</td><td>' . $e($c['artisan']) . '</td><td>' . $feeForm . '</td><td>' . $e($c['commission']) . '</td><td>' . $e($c['margin']) . '</td><td>' . $e(implode('; ', $c['note'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div><p>Il costo manuale vuoto ripristina il costo disponibile nel registro. La commissione 0 o mancante richiede verifica.</p>';
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('Errore lettura margini ordini: ' . $exception->getMessage(), 3);
            $html .= '<div class="alert alert-danger">Impossibile caricare i dati. Verifica che la vista e le tabelle richieste siano disponibili.</div>';
        }
        $this->content .= $html . '</div>';
        $this->context->smarty->assign('content', $this->content);
    }
}
