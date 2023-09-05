<?php

declare(strict_types=1);

namespace Reload;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use webignition\SymfonyConsole\TypedInput\TypedInput;

// phpcs:ignore SlevomatCodingStandard.Classes.RequireAbstractOrFinal
class JiraUserInfoCommand extends Command
{
    /**
     * Name of the command.
     *
     * @var string
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    protected static $defaultName = 'user-info';

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Get user info')
            ->setHelp('Lookup an email address and dump user data.')
            ->addArgument('email', InputArgument::REQUIRED, 'Email to lookup');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $typedInput = new TypedInput($input);

        $issue = new JiraSecurityIssue();

        $email = $typedInput->getStringArgument('email') ?? '';

        $data = $issue->findUser($email);

        $output->writeln(\print_r($data, true));

        return 0;
    }
}
