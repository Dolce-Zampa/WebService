<?php

namespace PS\Webservice\Service;

use Illuminate\Support\Facades\Log;
use PS\Webservice\Service\Exceptions\MailjetServiceException;
use PS\Webservice\Service\HttpServiceInterface;

class MailjetService
{
    private HttpServiceInterface $httpService;
    private const CONTACT_LIST_ID = 10517938;

    public function __construct(HttpServiceInterface $httpService)
    {
        $this->httpService = $httpService;
    }

    /**
     * Summary of createNewContact
     * @param string $email
     * @param string $firstName
     * @param string $lastName
     * @param bool $isExcluded
     * @throws MailjetServiceException
     * @return int with contact ID
     */
    public function createNewContact(string $email, string $firstName = '', string $lastName = '', bool $isExcluded = false): int
    {
        $this->httpService->setUrl('/v3/REST/contact');

        $data = [
            'Email' => $email,
            'Name' => $firstName . ' ' . $lastName,
            'IsExcludedFromCampaigns' => $isExcluded,
        ];

        $response = $this->httpService->invoke('POST', $data);

        if($response->failed()) {
            Log::error('Failed to create new contact', [
                'email' => $email,
                'response' => $response->getBody(),
            ]);
            throw new MailjetServiceException('Failed to create new contact: ' . $response->getBody());
        }

        $data = json_decode($response->getBody(), true);
        $contactId = $data['Data'][0]['ID'] ?? null;

        if (!$contactId) {
            Log::error('Failed to retrieve contact ID after creating new contact', [
                'email' => $email,
                'response' => $response->getBody(),
            ]);
            throw new MailjetServiceException('Failed to retrieve contact ID after creating new contact: ' . $response->getBody());
        }

        return $contactId;

    }

    /**
     * Summary of setContactListSubscription
     * @param int $contactId
     * @param int $listId
     * @return bool
     */
    public function setContactListSubscription(int $contactId, int|string $listId = self::CONTACT_LIST_ID): void
    {
        $this->httpService->setUrl('/v3/REST/listrecipient');

        $data = [
            "ContactID" => $contactId,
            "ListID" => $listId,
        ];

        $response = $this->httpService->invoke('POST', $data);

        if($response->failed()) {
            Log::error('Failed to subscribe contact to list', [
                'ContactID' => $contactId,
                'ListID' => $listId,
                'response' => $response->getBody()
            ]);
            throw new MailjetServiceException('Failed to subscribe contact to list: ' . $response->getBody());
        }

    }

    /**
     * Adds a contact to the newsletter lists only after an explicit
     * subscription choice. A declined or omitted choice leaves any existing
     * Mailjet subscription untouched.
     */
    public function subscribeToCustomerNewsletter(
        string $email,
        string $firstName,
        string $lastName,
        bool $hasNewsletterConsent,
        int|string $customerListId,
    ): void {
        if (!$hasNewsletterConsent) {
            return;
        }

        $contactId = $this->createNewContact($email, $firstName, $lastName);
        $this->setContactListSubscription($contactId, $customerListId);
        $this->setContactListSubscription($contactId);
    }

    /**
     * Sets Mailjet's built-in, per-contact consent for the open-tracking
     * pixel. The contact identifier endpoint also accepts a URL-encoded email
     * address, which avoids creating a second contact just to update consent.
     */
    public function setOpenTrackingConsent(string $email, bool $allowed): void
    {
        $this->httpService->setUrl('/v3/REST/contactdata/' . rawurlencode($email));

        $response = $this->httpService->invoke('PUT', [
            'Data' => [[
                'Name' => 'mjoptinopen',
                'Value' => $allowed ? '1' : '0',
            ]],
        ]);

        if ($response->failed()) {
            Log::error('Failed to update Mailjet open tracking consent', [
                'status' => $response->getHttpCode(),
            ]);
            throw new MailjetServiceException('Failed to update Mailjet open tracking consent');
        }
    }
    

}
