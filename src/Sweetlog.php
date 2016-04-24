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

    /** @var ArrayCollection */
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
     * @param string  $since
     */
    public function __construct($workspacePath, $force, $since)
    {
        $this->workspacePath = $workspacePath;
        $this->force = $force;
        $this->since = $since;
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

    public function run()
    {
        $this->commits = $this->gitLogToArray();
        $this->io->comment(count($this->commits) . ' commit(s) since "' . $this->since . '"');
        $this->modifyDisallowedCommits();
    }

    private function gitLogToArray()
    {
        $cmd = 'git log' .
            ' --pretty=format:\'{"commit": "%H","author": "%an","author_email": "%ae","date": "%ad","message": "%f"},\'' .
            ' --since=' . $this->since .
            ' --reverse' .
            ' ' . $this->workspacePath;
        $result = $this->execCmd($cmd);
        $result = '[' . substr($result, 0, -1) . ']';
        $result = json_decode($result, true);
        $result = (new ArrayCollection($result))->map(function ($commit) {
            $commit['date'] = new DateTime($commit['date']);

            return $commit;
        });

        return $result;
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

    /**
     * @param DateTime $date
     * @param integer  $days
     *
     * @return DateTime
     */
    private function sub(DateTime $date, $days)
    {
        $date = clone $date;

        return $date->sub(new DateInterval('P' . $days . 'D'));
    }

    /**
     * @param DateTime $date
     * @param integer  $days
     *
     * @return DateTime
     */
    private function add(DateTime $date, $days)
    {
        $date = clone $date;

        return $date->add(new DateInterval('P' . $days . 'D'));
    }

    private function modifyDisallowedCommits()
    {
        $this->commitsToModify = [];
        foreach ($this->commits as $key => &$commit) {
            if ($this->isWorkTime($commit['date'])) {
                if ($key > 0) {
                    $this->makeTheCommitAllowed($commit, $key);
                    $this->commitsToModify[] = $commit;
                } else {
                    $this->io->warning('commit ' . $commit['commit'] . ' will not be modify because we do not know the previous date (from filter configuration)');
                }
            }
        }
        $this->io->comment(count($this->commitsToModify) . ' commit(s) to modify');
        $this->displayCommitsToModify();
    }

    private function isWorkTime(DateTime $date)
    {
        $weekDay = $date->format('w');
        if (in_array($weekDay, [1, 2, 3, 4, 5])) {
            $hour = $date->format('H');
            if ($hour >= 9 && $hour <= 18) {
                //dump($hour);
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
        $previousAllowedCommitDate = $this->getPreviousAllowedCommitDate($key);
        $previousAllowedCommitDate = $previousAllowedCommitDate->modify('+10 seconds');
        $commit['date_modified'] = $previousAllowedCommitDate;
    }

    /**
     * @param $key
     *
     * @return DateTime
     * @throws Exception
     */
    private function getPreviousAllowedCommitDate($key)
    {
        //$this->io->comment('key='.$key);
        for ($i = $key; $i >= 0; $i--) {
            //$this->io->comment($i);
            $dateKey = 'date';
            if (isset($this->commits[$i]['modified_date'])) {
                return clone $this->commits[$i]['modified_date'];
            } elseif (!$this->isWorkTime($this->commits[$i][$dateKey])) {
                return clone $this->commits[$i]['date'];
            }
        }
        throw new Exception(
            'No previous allowed commit found for this periode (' .
            $this->commits[$key]['commit'] . ', ' . $this->formatHumanDate($this->commits[$key]['date']) . ', ' .
            $key . ')'
        );
    }

    private function displayCommitsToModify()
    {
        $data = [];
        foreach ($this->commitsToModify as $commitsToModify) {
            $data[] = [
                substr($commitsToModify['commit'], 0, 7),
                substr($commitsToModify['message'], 0, 50) . (strlen($commitsToModify['message']) > 50 ? '...' : ''),
                $this->formatHumanDate($commitsToModify['date']),
                $this->formatHumanDate($commitsToModify['date_modified']),
            ];
        }

        $table = new Table($this->output);
        $table->setHeaders(['Hash', 'Message', 'Date', 'Fixed date'])->setRows($data);
        $table->render();
    }

    private function formatHumanDate(DateTime $date)
    {
        return strftime('%a %e %b %H:%M:%S', $date->getTimestamp());
    }
}
