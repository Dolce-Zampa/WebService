<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Dzordini extends Module
{
    public $tabs = [
        [
            'class_name' => 'AdminDzOrdini',
            'name' => 'Margini ordini',
            'visible' => true,
            'parent_class_name' => 'AdminParentOrders',
        ],
    ];

    public function __construct()
    {
        $this->name = 'dzordini';
        $this->version = '1.0.0';
        $this->author = 'DolceZampa';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'Margini ordini DolceZampa';
        $this->description = 'Vista e CSV dei margini parziali degli ordini.';
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        // Retain manually verified fees on uninstall/reinstall.
        $table = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'dz_order_margin` (
            `id_order` INT UNSIGNED NOT NULL,
            `fee_override` DECIMAL(20,2) DEFAULT NULL,
            PRIMARY KEY (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        $view = 'CREATE OR REPLACE VIEW `' . _DB_PREFIX_ . 'dz_order_margin_view` AS
            SELECT o.`id_order`, o.`id_shop`, o.`id_currency`, o.`reference`, o.`date_add`,
                o.`current_state`, o.`valid`, o.`payment`, o.`total_paid_tax_incl`,
                o.`total_shipping_tax_incl`, o.`total_wrapping_tax_incl`, m.`fee_override`
            FROM `' . _DB_PREFIX_ . 'orders` o
            LEFT JOIN `' . _DB_PREFIX_ . 'dz_order_margin` m ON m.`id_order` = o.`id_order`';

        return parent::install() && Db::getInstance()->execute($table) && Db::getInstance()->execute($view);
    }

    public function uninstall()
    {
        return Db::getInstance()->execute('DROP VIEW IF EXISTS `' . _DB_PREFIX_ . 'dz_order_margin_view`')
            && parent::uninstall();
    }
}
