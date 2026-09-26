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
        $existingCustomer = $this->db->table(Customer::tableName())
            ->where('email', $customer->email)
            ->first();

        if ($existingCustomer) {
            // Se esiste un cliente con la stessa email, aggiorna il record esistente
            $this->db->table(Customer::tableName())
                ->where('id_customer', $existingCustomer->id_customer)
                ->update([
                    'sub' => $customer->sub,
                    'passwd' => sha1($customer->password),
                    'birthday' => $customer->birthday,
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'newsletter' => $customer->newsletter,
                    'date_upd' => Carbon::now(),
                    'uuid' => $customer->uuid,
                    'active' => 1,
                    'id_lang' => 1, //FIXME: language should be dynamic based on customer preference
                    'newsletter_date_add' => $customer->newsletter_date_add ?? null,
                    'max_payment_days' => 0,
                    'secure_key' => sha1($customer->email)
                ]);
        } else if($existingCustomer) { //se esiste ritorna errore
            throw new RuntimeException("Customer with email already exists.");
        } else {
            // Altrimenti, crea un nuovo record
            $this->db->table(Customer::tableName())
                ->insert([
                    'sub' => $customer->sub,
                    'email' => $customer->email,
                    'passwd' => sha1($customer->password),
                    'uuid' => $customer->uuid,
                    'birthday' => $customer->birthday,
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'newsletter' => $customer->newsletter,
                    'id_gender' => $customer->id_gender,
                    'date_add' => Carbon::now(),
                    'date_upd' => Carbon::now(),
                    'active' => 1,
                    'id_lang' => 1, //FIXME: language should be dynamic based on customer preference
                    'newsletter_date_add' => $customer->newsletter_date_add ?? null,
                    'max_payment_days' => 0,
                    'secure_key' => sha1($customer->email)
                ]);
        }

        // Recupera il cliente appena creato o aggiornato
        $customerRecord = $this->db->table(Customer::tableName())
            ->where('email', $customer->email)
            ->first();

        return $customerRecord;
    }

    public function getCustomerByEmail(string $email): ?\stdClass
    {
        return $this->db->table(Customer::tableName())
            ->where('email', $email)
            ->first();
    }
}