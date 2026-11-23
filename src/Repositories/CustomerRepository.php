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
    public function saveNewCustomer(ObjectInterface $customer, ?string $sub = null, bool $allowGuestConversion = false): \stdClass
    {
        $password = $customer->get('password');
        if (!is_string($password) || trim($password) === '') {
            throw new RuntimeException('A non-empty customer password is required to create an account');
        }

        $matches = $this->db->table(Customer::tableName())
            ->where('email', $customer->email)->where('deleted', 0)->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('Multiple customer records require manual reconciliation');
        }
        $existingCustomer = $matches->first();
        if ($existingCustomer && (!$allowGuestConversion || (int) $existingCustomer->is_guest !== 1 || !empty($existingCustomer->sub))) {
            throw new RuntimeException('Customer account already exists');
        }

        $isSeller = $customer->get('is_seller');
        $id_default_group = $isSeller ? 5 : 3; 
        $idLang = $customer->get('id_lang') ?: $this->db->table('configuration')
            ->where('name', 'PS_LANG_DEFAULT')
            ->value('value');
        if (!is_numeric($idLang) || (int) $idLang <= 0) {
            throw new RuntimeException('A valid customer language is required');
        }

        if ($existingCustomer) {
            // Roll back the profile update if customer group assignment fails.
            $this->db->getConnection()->transaction(function () use ($existingCustomer, $sub, $customer, $password, $idLang, $id_default_group): void {
                $updated = $this->db->table(Customer::tableName())
                    ->where('id_customer', $existingCustomer->id_customer)
                    ->where('is_guest', 1)->where('deleted', 0)
                    ->where(function ($query) { $query->whereNull('sub')->orWhere('sub', ''); })
                    ->update([
                        'sub' => $sub ?? $customer->sub,
                        'passwd' => password_hash($password, PASSWORD_BCRYPT),
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
                        'is_guest' => 0,
                        'id_default_group' => $id_default_group
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Guest profile was changed during registration');
                }
                $guestGroup = $this->db->table('configuration')->where('name', 'PS_GUEST_GROUP')->value('value');
                if (is_numeric($guestGroup) && (int) $guestGroup !== $id_default_group) {
                    $this->db->table('customer_group')->where('id_customer', $existingCustomer->id_customer)
                        ->where('id_group', (int) $guestGroup)->delete();
                }
                $this->db->table('customer_group')->insertOrIgnore([
                    'id_customer' => $existingCustomer->id_customer, 'id_group' => $id_default_group,
                ]);
            });
        } else {
            $this->db->getConnection()->transaction(function () use ($sub, $customer, $password, $idLang, $id_default_group): void {
                $customerId = $this->db->table(Customer::tableName())
                    ->insertGetId([
                        'sub' => $sub ?? $customer->sub,
                        'email' => $customer->email,
                        'passwd' => password_hash($password, PASSWORD_BCRYPT),
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
                $this->db->table('customer_group')->insertOrIgnore([
                    'id_customer' => $customerId, 'id_group' => $id_default_group,
                ]);
            });
        }

        // Recupera il cliente appena creato o aggiornato
        $customerRecord = $this->db->table(Customer::tableName())
            ->where('email', $customer->email)->where('deleted', 0)
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