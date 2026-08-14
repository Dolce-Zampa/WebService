<?php
declare(strict_types=1);

namespace PS\Webservice\Repositories;

use Carbon\Carbon;
use PS\Webservice\Domain\Models\PS\Customer;
use PS\Webservice\Domain\ObjectInterface;
use RuntimeException;

class CustomerRepository extends PrestashopRepository implements RepositoryInterface
{

    /**
     * Summary of saveNewCustomer
     * @throws RuntimeException
     * @return void
     */
    public function saveNewCustomer(ObjectInterface $customer): \stdClass
    {
        //Patch per utenti creati prima della versione 2 di DolceZampa, dobbiamo preservare i vecchi customers
        $existingCustomer = $this->db->table(Customer::tableName())
            ->where('email', 'like', '%@%')
            ->first();

        if ($existingCustomer && $existingCustomer->created_at < '2026-08-15 00:00:00') {
            // Se esiste un cliente con la stessa email, aggiorna il record esistente
            $this->db->table(Customer::tableName())
                ->where('id_customer', $existingCustomer->id_customer)
                ->update([
                    'sub' => $customer->sub,
                    'passwd' => $customer->password,
                    'birthday' => $customer->birthday,
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'newsletter' => $customer->newsletter,
                    'date_upd' => Carbon::now(),
                    'uuid' => $customer->uuid,
                ]);
        } else if($existingCustomer) { //se esiste ritorna errore
            throw new RuntimeException("Customer with email already exists.");
        } else {
            // Altrimenti, crea un nuovo record
            $this->db->table(Customer::tableName())
                ->insert([
                    'sub' => $customer->sub,
                    'email' => $customer->email,
                    'passwd' => $customer->password,
                    'uuid' => $customer->uuid,
                    'birthday' => $customer->birthday,
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'newsletter' => $customer->newsletter,
                    'date_add' => Carbon::now(),
                    'date_upd' => Carbon::now(),
                ]);
        }

        // Recupera il cliente appena creato o aggiornato
        $customerRecord = $this->db->table(Customer::tableName())
            ->where('email', $customer->email)
            ->first();

        return $customerRecord;
    }
}