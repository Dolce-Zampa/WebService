<?php
// modules/tuomodulo/controllers/front/ApiMail.php
require_once dirname(__FILE__) . '/../../classes/MlabFactoryApiBaseModuleFrontController.php';

class webserviceapimailerModuleFrontController extends MlabFactoryApiBaseModuleFrontController
{
    /**
     * Gestisce la richiesta API
     */
    protected function handleRequest()
    {
        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        $this->assertRequestMethod(array('POST'));

        return $this->sendMail();
    }

    /**
     * Invia l'email con i dati forniti
     */
    protected function sendMail()
    {
        $payload = $this->getJsonPayload();
        
        // Validazione dei campi obbligatori
        $requiredFields = ['to_email', 'subject', 'template'];
        foreach ($requiredFields as $field) {
            if (empty($payload[$field])) {
                throw new MlabFactoryApiException(
                    sprintf('Field "%s" is required.', $field), 
                    422
                );
            }
        }

        // Validazione email
        $toEmail = trim((string) $payload['to_email']);
        if (!Validate::isEmail($toEmail)) {
            throw new MlabFactoryApiException('Invalid recipient email address.', 422);
        }

        // Validazione template
        $template = trim((string) $payload['template']);
        if (!$this->templateExists($template)) {
            throw new MlabFactoryApiException(
                sprintf('Template "%s" does not exist.', $template), 
                422
            );
        }

        // Recupera il destinatario
        $toName = !empty($payload['to_name']) ? trim((string) $payload['to_name']) : null;
        $subject = trim((string) $payload['subject']);
        $templateVars = !empty($payload['template_vars']) && is_array($payload['template_vars']) 
            ? $payload['template_vars'] 
            : [];

        // Costruisci le variabili del template
        $templateVars = $this->buildTemplateVars($templateVars);

        // Gestione allegati
        $attachments = [];
        if (!empty($payload['attachments']) && is_array($payload['attachments'])) {
            $attachments = $this->processAttachments($payload['attachments']);
        }

        // Lingua - permette di specificare id_lang via URL o payload
        $idLang = (int) Tools::getValue('id_lang', Configuration::get('PS_LANG_DEFAULT'));
        if (!empty($payload['id_lang'])) {
            $idLang = (int) $payload['id_lang'];
        }

        // Invio email
        try {
            $mailSent = Mail::Send(
                $idLang,
                $template,
                $subject,
                $templateVars,
                $toEmail,
                $toName,
                null, // Mittente email (default)
                null, // Mittente nome (default)
                $attachments,
                null, // SMTP personalizzato
                $this->getTemplatePath() // Percorso personalizzato
            );

            if (!$mailSent) {
                throw new MlabFactoryApiException('Unable to send email.', 500);
            }

            // Log dell'invio
            $this->logEmailSent($toEmail, $template, $subject);

            return [
                'success' => true,
                'message' => 'Email sent successfully.',
                'data' => [
                    'to_email' => $toEmail,
                    'to_name' => $toName,
                    'template' => $template,
                    'subject' => $subject,
                    'id_lang' => $idLang
                ]
            ];

        } catch (Exception $e) {
            throw new MlabFactoryApiException(
                'Error sending email: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Costruisce le variabili del template
     */
    protected function buildTemplateVars(array $customVars): array
    {
        $context = Context::getContext();
        
        // Variabili di sistema disponibili per tutti i template
        $systemVars = [
            '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
            '{shop_url}' => $context->link->getPageLink('index', true),
            '{shop_logo}' => $context->link->getMediaLink(_PS_IMG_ . 'logo.png'),
            '{shop_email}' => Configuration::get('PS_SHOP_EMAIL'),
            '{current_date}' => date('Y-m-d H:i:s'),
            '{site_url}' => Tools::getShopDomainSsl(true),
        ];

        // Aggiungi variabili contestuali
        if ($this->context->customer && $this->context->customer->id) {
            $systemVars['{customer_firstname}'] = $this->context->customer->firstname;
            $systemVars['{customer_lastname}'] = $this->context->customer->lastname;
            $systemVars['{customer_email}'] = $this->context->customer->email;
        }

        // Unisci con le variabili personalizzate e formatta le chiavi
        foreach ($customVars as $key => $value) {
            // Se la chiave non ha già le graffe, le aggiungiamo
            if (strpos($key, '{') !== 0) {
                $key = '{' . $key . '}';
            }
            $systemVars[$key] = $value;
        }

        return $systemVars;
    }

    /**
     * Verifica se il template esiste nella lingua specificata
     */
    protected function templateExists(string $templateName): bool
    {
        $idLang = (int) Tools::getValue('id_lang', Configuration::get('PS_LANG_DEFAULT'));
        $path = $this->getTemplatePath();
        
        // Verifica l'esistenza dei file HTML e TXT
        $htmlFile = $path . $this->getLanguageIso($idLang) . '/' . $templateName . '.html';
        $txtFile = $path . $this->getLanguageIso($idLang) . '/' . $templateName . '.txt';
        
        // Almeno uno dei due file deve esistere
        return file_exists($htmlFile) || file_exists($txtFile);
    }

    /**
     * Ottiene il percorso dei template
     */
    protected function getTemplatePath(): string
    {
        // Puoi personalizzare il percorso base
        return _PS_MODULE_DIR_ . 'tuomodulo/mails/';
    }

    /**
     * Ottiene l'ISO della lingua
     */
    protected function getLanguageIso(int $idLang): string
    {
        $lang = new Language($idLang);
        if (!Validate::isLoadedObject($lang)) {
            $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
            $lang = new Language($idLang);
        }
        return $lang->iso_code . '/';
    }

    /**
     * Processa gli allegati
     */
    protected function processAttachments(array $attachments): array
    {
        $processed = [];
        
        foreach ($attachments as $attachment) {
            if (empty($attachment['path']) && empty($attachment['content'])) {
                continue;
            }

            $fileData = [];
            
            // Se è un percorso file
            if (!empty($attachment['path']) && file_exists($attachment['path'])) {
                $fileData['file'] = $attachment['path'];
                if (!empty($attachment['name'])) {
                    $fileData['name'] = $attachment['name'];
                }
                if (!empty($attachment['mime'])) {
                    $fileData['mime'] = $attachment['mime'];
                }
                $processed[] = $fileData;
                continue;
            }

            // Se è contenuto base64
            if (!empty($attachment['content']) && !empty($attachment['name'])) {
                $decoded = base64_decode($attachment['content'], true);
                if ($decoded !== false) {
                    $tempPath = tempnam(sys_get_temp_dir(), 'mail_attach_');
                    file_put_contents($tempPath, $decoded);
                    
                    $processed[] = [
                        'file' => $tempPath,
                        'name' => $attachment['name'],
                        'mime' => $attachment['mime'] ?? mime_content_type($tempPath) ?: 'application/octet-stream'
                    ];
                }
            }
        }
        
        return $processed;
    }

    /**
     * Registra l'invio della mail nel log
     */
    protected function logEmailSent(string $to, string $template, string $subject): void
    {
        PrestaShopLogger::addLog(
            sprintf('Email sent via API: to=%s, template=%s, subject=%s', 
                $to, 
                $template, 
                $subject
            ),
            1, // Info
            null,
            'Mail',
            null,
            true
        );
    }

    /**
     * Invio email con template da modulo (metodo di utilità)
     */
    protected function sendMailFromModule(
        string $template,
        string $subject,
        array $variables,
        string $toEmail,
        ?string $toName = null,
        ?int $idLang = null,
        ?string $moduleName = null
    ): bool {
        $idLang = $idLang ?? (int) Configuration::get('PS_LANG_DEFAULT');
        $moduleName = $moduleName ?? 'tuomodulo';
        
        return Mail::Send(
            $idLang,
            $template,
            $subject,
            $variables,
            $toEmail,
            $toName,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $moduleName . '/mails/'
        );
    }
}