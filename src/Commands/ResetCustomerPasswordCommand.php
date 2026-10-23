<?php

declare(strict_types=1);

namespace PS\Webservice\Commands;

use PS\Webservice\Facades\AwsCognitoClient;
use PS\Webservice\Service\MailerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:customers:reset-password',
    description: 'Imposta una nuova password Cognito per un cliente'
)]
final class ResetCustomerPasswordCommand extends Command
{
    private const PASSWORD_PATTERN = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';

    public function __construct(private readonly MailerInterface $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'email',
            InputArgument::OPTIONAL,
            'Email del cliente; se omessa verrà richiesta in modo interattivo'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $questions = $this->getHelper('question');
        \assert($questions instanceof QuestionHelper);

        $email = trim((string) $input->getArgument('email'));
        if ($email === '') {
            $email = trim((string) $questions->ask($input, $output, new Question('Email del cliente: ')));
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 64) {
            $io->error('Inserisci un indirizzo email valido (massimo 64 caratteri).');
            return Command::INVALID;
        }

        $password = $this->askForPassword($questions, $input, $output, 'Nuova password: ');
        $confirmation = $this->askForPassword($questions, $input, $output, 'Conferma nuova password: ');

        if ($password !== $confirmation) {
            $io->error('Le due password non coincidono. Nessuna modifica è stata eseguita.');
            return Command::INVALID;
        }

        if (!$this->isValidPassword($password)) {
            $io->error('La password deve contenere 8-64 caratteri, una minuscola, una maiuscola, una cifra e un carattere speciale tra @$!%*?&.');
            return Command::INVALID;
        }

        $proceed = new ConfirmationQuestion(
            sprintf('Impostare la nuova password per <%s>? [y/N] ', $email),
            false
        );
        if (!$questions->ask($input, $output, $proceed)) {
            $io->note('Operazione annullata.');
            return Command::SUCCESS;
        }

        try {
            AwsCognitoClient::setUserPassword($email, $password, true);
        } catch (\Throwable $exception) {
            $io->error('Non è stato possibile aggiornare la password in Cognito.');
            return Command::FAILURE;
        }

        try {
            $this->mailer->sendResetPasswordConfirmationMail($email);
        } catch (\Throwable $exception) {
            $io->warning('Password aggiornata, ma non è stato possibile inviare l’email di conferma.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Password aggiornata per <%s>. È stata inviata l’email di conferma.', $email));
        return Command::SUCCESS;
    }

    private function askForPassword(QuestionHelper $questions, InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $question = new Question($prompt);
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        return (string) $questions->ask($input, $output, $question);
    }

    private function isValidPassword(string $password): bool
    {
        return strlen($password) <= 64 && preg_match(self::PASSWORD_PATTERN, $password) === 1;
    }
}
