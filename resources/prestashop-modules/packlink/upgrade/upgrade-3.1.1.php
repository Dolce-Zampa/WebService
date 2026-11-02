<?php
/**
 * 2025 Packlink
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Apache License 2.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * http://www.apache.org/licenses/LICENSE-2.0.txt
 *
 * @author    Packlink <support@packlink.com>
 * @copyright 2025 Packlink Shipping S.L
 * @license   http://www.apache.org/licenses/LICENSE-2.0.txt  Apache License 2.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Updates module to version 3.1.1.
 *
 * @return boolean
 *
 * @noinspection PhpUnused
 */
function upgrade_module_3_1_1()
{
    $query = new \DbQuery();
    $query->select('id, data')
        ->from(bqSQL('packlink_entity'))
        ->where('index_1="defaultParcel"');

    $records = \Db::getInstance()->executeS($query);
    foreach ($records as $record) {
        if (empty($record)) {
            continue;
        }

        $data = json_decode($record['data'], true);
        if (!empty($data['value']['weight'])) {
            $weight = (float)$data['value']['weight'];
            $data['value']['weight'] = !empty($weight) ? $weight : 1;
        }

        foreach (array('length', 'height', 'width') as $field) {
            if (!empty($data['value'][$field])) {
                $fieldValue = (int)$data['value'][$field];
                $data['value'][$field] = !empty($fieldValue) ? $fieldValue : 10;
            }
        }

        if (!empty($record['id'])) {
            \Db::getInstance()->update(
                'packlink_entity',
                array(
                    'data' => pSQL(json_encode($data), true)
                ),
                '`id` = ' . $record['id']
            );
        }
    }

    return true;
}
