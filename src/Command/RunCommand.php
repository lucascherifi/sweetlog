<?php

namespace Kasifi\Sweetlog\Command;

use DateTime;
use Exception;
use Kasifi\Sweetlog\Sweetlog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class RunCommand extends Command
{
    /**
     * return void
     */
    protected function configure()
    {
        $this
            ->setName('run')
            ->setDescription('Rewrite log sweetly')
            ->addArgument(
                'workspace-path',
                InputArgument::OPTIONAL,
                'The workspace path of the local git checkout',
                '.'
            )
            ->addArgument(
                'since',
                InputArgument::OPTIONAL,
                'The date since the log should be rewrite (https://git-scm.com/book/tr/v2/Git-Basics-Viewing-the-Commit-History#Limiting-Log-Output)',
                '2.weeks'
            )
            ->addOption(
                'only-one-commit',
                null,
                InputOption::VALUE_REQUIRED,
                'If set, on this commit will rewrite'
            )
            ->addOption(
                'only-one-commit-date',
                null,
                InputOption::VALUE_REQUIRED,
                'Required if only-one-commit has been set'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'If set, the commits will not be pushed to the repository URL.'
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return void
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $since = $input->getArgument('since');
        $force = $input->getOption('force');
        $workspacePath = $input->getArgument('workspace-path');
        $fs = new Filesystem();
        if (!$fs->exists($workspacePath)) {
            throw new Exception('Workspace path not found: ' . $workspacePath);
        }
        $workspacePath = realpath($workspacePath);
        $io->comment('Workspace used: ' . $workspacePath);

        $sweetlog = new Sweetlog($workspacePath, $force);
        $sweetlog->setIo($io, $input, $output);

        $onlyOneCommit = $input->getOption('only-one-commit');
        if ($onlyOneCommit) {
            $input->getOption('only-one-commit');
            $onlyOneCommitDate = $input->getOption('only-one-commit-date');
            if (!$onlyOneCommitDate) {
                throw new Exception('If only-one-commit is set, only-one-commit-date should be set too.');
            }
            try {
                $newDate = DateTime::createFromFormat('Y-m-d H:i:s', $onlyOneCommitDate);
            } catch (Exception $e) {
                throw new Exception('Only-one-commit-date format should be as: Y-m-d H:i:s.');
            }
            $sweetlog->runOnlyOneCommit($onlyOneCommit, $newDate, $newDate);
        } else {
            $sweetlog->run($since);
        }
    }
}
