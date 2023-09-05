<?php

declare(strict_types=1);

namespace Reload;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use webignition\SymfonyConsole\TypedInput\TypedInput;

// phpcs:ignore SlevomatCodingStandard.Classes.RequireAbstractOrFinal
class JiraSecurityIssueCommand extends Command
{
    /**
     * Name of the command.
     *
     * @var string
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    protected static $defaultName = 'ensure';

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Create issue')
            ->setHelp('Create a Jira issue.')
            ->addArgument('title', InputArgument::REQUIRED, 'Title of issue')
            ->addArgument('body', InputArgument::REQUIRED, 'Body of issue')
            ->addOption(
                'key-label',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Label keys to use',
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $typedInput = new TypedInput($input);

        $issue = new JiraSecurityIssue();

        $issue->setTitle($typedInput->getStringArgument('title') ?? '')
            ->setBody($typedInput->getStringArgument('body') ?? '');

        /** @var array<string> $labels */
        $labels = $input->getOption('key-label');

        foreach ($labels as $label) {
            $issue->setKeyLabel($label);
        }

        $id = $issue->ensure();

        $output->writeln($id);

        return 0;
    }
}
