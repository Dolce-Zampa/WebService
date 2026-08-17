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

        $response = $this->httpService->invoke('POST', [
            'ContactsList' => [
                'ID' => 123456, // Replace with your actual contact list ID
            ],
            'Contacts' => [$data],
        ]);

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

        $this->setContactListSubscription($contactId);

        return $contactId;

    }

    /**
     * Summary of setContactListSubscription
     * @param int $contactId
     * @param int $listId
     * @return bool
     */
    public function setContactListSubscription(int $contactId, int $listId = self::CONTACT_LIST_ID): void
    {
        $this->httpService->setUrl('/v3/REST/listrecipient');

        $data = [
            "ContactID" => $contactId,
            "ListID" => $listId,
        ];

        $response = $this->httpService->invoke('POST', $data);

        if($response->failed()) {
            Log::error('Failed to subscribe contact to list', [
                'contact_id' => $contactId,
                'list_id' => $listId,
                'response' => $response->getBody(),
            ]);
            throw new MailjetServiceException('Failed to subscribe contact to list: ' . $response->getBody());
        }

    }
    

}