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
        $password = $customer->get('password');
        if (!is_string($password) || trim($password) === '') {
            throw new RuntimeException('A non-empty customer password is required to create an account');
        }

        $existingCustomer = $this->db->table(Customer::tableName())
            ->where('email', $customer->email)
            ->first();

        $isSeller = $customer->get('is_seller');
        $id_default_group = $isSeller ? 5 : 3; 
        $idLang = $customer->get('id_lang') ?: $this->db->table('configuration')
            ->where('name', 'PS_LANG_DEFAULT')
            ->value('value');
        if (!is_numeric($idLang) || (int) $idLang <= 0) {
            throw new RuntimeException('A valid customer language is required');
        }

        if ($existingCustomer) {
            // Se esiste un cliente con la stessa email, aggiorna il record esistente
            $this->db->table(Customer::tableName())
                ->where('id_customer', $existingCustomer->id_customer)
                ->update([
                    'sub' => $customer->sub,
                    'passwd' => sha1($password),
                    'birthday' => $customer->birthday,
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'newsletter' => $customer->newsletter,
                    'date_upd' => Carbon::now(),
                    'uuid' => $customer->uuid,
                    'active' => 1,
                    'id_lang' => (int) $idLang,
                    'newsletter_date_add' => $customer->newsletter_date_add ?? null,
                    'max_payment_days' => 0,
                    'secure_key' => md5(uniqid((string) mt_rand(), true)) , // only 32 char
                    'id_default_group' => $id_default_group
                ]);
        } else if($existingCustomer) { //se esiste ritorna errore
            throw new RuntimeException("Customer with email already exists.");
        } else {
            // Altrimenti, crea un nuovo record
            $this->db->table(Customer::tableName())
                ->insert([
                    'sub' => $customer->sub,
                    'email' => $customer->email,
                    'passwd' => sha1($password),
                    'uuid' => $customer->uuid,
                    'birthday' => $customer->birthday,
                    'firstname' => $customer->firstname,
                    'lastname' => $customer->lastname,
                    'newsletter' => $customer->newsletter,
                    'id_gender' => $customer->id_gender,
                    'date_add' => Carbon::now(),
                    'date_upd' => Carbon::now(),
                    'active' => 1,
                    'id_lang' => (int) $idLang,
                    'newsletter_date_add' => $customer->newsletter_date_add ?? null,
                    'max_payment_days' => 0,
                    'secure_key' => md5(microtime() . rand()),
                    'id_default_group' => $id_default_group
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