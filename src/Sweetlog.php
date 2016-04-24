<?php

namespace Kasifi\Sweetlog;

use DateInterval;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Exception;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class Sweetlog
{
    /** @var SymfonyStyle */
    private $io;

    /** @var string */
    private $workspacePath;

    /**
     * @var bool
     */
    private $force;

    /**
     * @var string
     */
    private $since;

    /** @var array */
    private $commits;

    /** @var ArrayCollection */
    private $commitsToModify;

    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /**
     * Sweetlog constructor.
     *
     * @param string  $workspacePath
     * @param boolean $force
     */
    public function __construct($workspacePath, $force)
    {
        $this->workspacePath = $workspacePath;
        $this->force = $force;
    }

    /**
     * @param SymfonyStyle    $io
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function setIo($io, InputInterface $input, OutputInterface $output)
    {
        $this->io = $io;
        $this->input = $input;
        $this->output = $output;
    }

    public function run($since)
    {
        $this->since = $since;
        $this->commits = $this->gitLogToArray();
        $this->io->comment(count($this->commits) . ' commit(s) since "' . $this->since . '"');
        $this->buildToFixCommitsList();
        $this->displayCommitsToModify();
        if ($this->force) {
            while (count($this->commitsToModify)) {
                $firstCommitToModify = $this->commitsToModify[0];
                $this->io->comment(
                    'Applying new date for ' . substr($firstCommitToModify['commit'], 0, 7) .
                    ' ' . $this->formatHumanDate($firstCommitToModify['author_date']) . ' => ' .
                    ' ' . $this->formatHumanDate($firstCommitToModify['author_date_modified']) .
                    ' ...'
                );
                $commitHash = $firstCommitToModify['commit'];
                $authorDate = $firstCommitToModify['author_date_modified'];
                $committerDate = $firstCommitToModify['committer_date_modified'];
                $this->applyNewDate($commitHash, $authorDate, $committerDate);
                $this->pushForce();
                $this->commits = $this->gitLogToArray();
                $this->buildToFixCommitsList();
                if (count($this->commitsToModify)) {
                    $this->io->comment('Still ' . count($this->commitsToModify) . ' commit(s) to modify...');
                }
            }
            $this->io->success('All dates are OK.');
        }
    }

    private function gitLogToArray()
    {
        $cmd = 'git log' .
            ' --pretty=format:\'{"commit": "%H","author": "%an","author_email": "%ae","author_date": "%ad","committer_date": "%cd","message": "%f"},\'' .
            ' --since=' . $this->since .
            ' --reverse' .
            ' ' . $this->workspacePath;
        $result = $this->execCmd($cmd);
        $result = '[' . substr($result, 0, -1) . ']';
        $result = json_decode($result, true);
        $result = (new ArrayCollection($result))->map(function ($commit) {
            $commit['author_date'] = new DateTime($commit['author_date']);
            $commit['committer_date'] = new DateTime($commit['committer_date']);

            return $commit;
        });

        return $result->toArray();
    }

    /**
     * @param string $cmd
     * @param bool   $ignoreErrors
     *
     * @return string
     * @throws Exception
     */
    private function execCmd($cmd, $ignoreErrors = false)
    {
        $cmd = 'cd ' . $this->workspacePath . ' && ' . $cmd;
        if ($this->io->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->io->comment($cmd);
        }
        $process = new Process($cmd);

        $process->run(function ($type, $buffer) use ($ignoreErrors) {
            if (Process::ERR === $type) {
                if ($ignoreErrors) {
                    if ($this->io->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $this->io->comment($buffer);
                    }
                } else {
                    $this->io->error($buffer);
                }
            } else {
                if ($this->io->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                    $this->io->comment($buffer);
                }
            }
        });

        if (!$ignoreErrors && !$process->isSuccessful()) {
            throw new Exception($process->getOutput() . $process->getErrorOutput());
        }

        return $process->getIncrementalOutput();
    }

    private function buildToFixCommitsList()
    {
        $this->commitsToModify = [];
        foreach ($this->commits as $key => &$commit) {
            if ($this->isWorkTime($commit['author_date'])) {
                if ($key > 0) {
                    $this->makeTheCommitAllowed($commit, $key);
                    $this->commitsToModify[] = $commit;
                } else {
                    throw new Exception(
                        'First commit ' . $commit['commit'] .
                        ' of the list (from filter configuration) is not at a correct date. Please fix it before.'
                    );
                }
            }
        }
    }

    private function isWorkTime(DateTime $date)
    {
        $weekDay = $date->format('w');
        if (in_array($weekDay, [1, 2, 3, 4, 5])) {
            $hour = $date->format('H');
            if ($hour >= 9 && $hour <= 18) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $commit
     * @param $key
     *
     * @throws Exception
     */
    private function makeTheCommitAllowed(&$commit, $key)
    {
        $previousAllowedCommitDate = $this->getPreviousAllowedCommitDate($key, 'author');
        $previousAllowedCommitDate = $previousAllowedCommitDate->modify('+' . rand(10, 50) . ' seconds');
        $commit['author_date_modified'] = $previousAllowedCommitDate;
        $commit['committer_date_modified'] = $previousAllowedCommitDate;
    }

    /**
     * @param $key
     *
     * @return DateTime
     * @throws Exception
     */
    private function getPreviousAllowedCommitDate($key, $type)
    {
        for ($i = $key; $i >= 0; $i--) {
            $dateKey = $type . '_date';
            if (isset($this->commits[$i][$type . '_date_modified'])) {

                return clone $this->commits[$i][$type . '_date_modified'];
            } elseif (!$this->isWorkTime($this->commits[$i][$dateKey])) {
                return clone $this->commits[$i][$type . '_date'];
            }
        }
        throw new Exception(
            'No previous allowed commit found for this periode (' .
            $this->commits[$key]['commit'] . ', ' . $this->formatHumanDate($this->commits[$key][$type . '_date']) . ', ' .
            $key . ')'
        );
    }

    private function displayCommitsToModify()
    {
        $this->io->comment(count($this->commitsToModify) . ' commit(s) to modify');
        if (count($this->commitsToModify)) {
            $data = [];
            foreach ($this->commitsToModify as $commitsToModify) {
                if ($commitsToModify['committer_date'] == $commitsToModify['author_date']) {
                    $committerDate = 'identical';
                } else {
                    $committerDate = $this->formatHumanDate($commitsToModify['committer_date']);
                }

                $data[] = [
                    substr($commitsToModify['commit'], 0, 7),
                    substr($commitsToModify['message'], 0, 45) . (strlen($commitsToModify['message']) > 45 ? '...' : ''),
                    $this->formatHumanDate($commitsToModify['author_date']),
                    $this->formatHumanDate($commitsToModify['author_date_modified']),
                    $committerDate,
                    $this->formatHumanDate($commitsToModify['committer_date_modified']),
                ];
            }

            $table = new Table($this->output);
            $table->setHeaders([
                'Hash', 'Message', 'Author Date', 'Author date modified', 'Com. Date', 'Com. Date modified',
            ])->setRows($data);
            $table->render();
        }
    }

    private function formatHumanDate(DateTime $date)
    {
        return strftime('%a %e %b %H:%M:%S', $date->getTimestamp());
    }

    private function applyNewDate($commitHash, DateTime $newAuthorDate, DateTime $newCommitterDate)
    {
        $cmd = 'git filter-branch -f --env-filter \
    \'if [ $GIT_COMMIT = ' . $commitHash . ' ]
     then
         export GIT_AUTHOR_DATE="' . $newAuthorDate->format('r') . '"
         export GIT_COMMITTER_DATE="' . $newCommitterDate->format('r') . '"
     fi\'';
        $this->execCmd($cmd);
    }

    private function pushForce()
    {
        $cmd = 'git push --force';
        $this->execCmd($cmd, true);
    }

    public function runOnlyOneCommit($commitHash, DateTime $newAuthorDate, DateTime $newCommitterDate)
    {
        $this->io->comment('Updating commit ' . $commitHash . ' date to: ' . $this->formatHumanDate($newAuthorDate));
        $this->applyNewDate($commitHash, $newAuthorDate, $newCommitterDate);
        $this->pushForce();
    }
}
