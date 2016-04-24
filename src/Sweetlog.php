<?php

namespace Kasifi\Sweetlog;

use DateInterval;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Exception;
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
     * @param SymfonyStyle $io
     */
    public function setIo($io)
    {
        $this->io = $io;
    }

    public function run()
    {
        $this->commits = $this->gitLogToArray();
        dump($this->commits);
        $this->io->comment(count($this->commits) . ' commit(s) since "' . $this->since . '"');
        $commitsToModify = $this->checkCommitsToModify();
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

    private function checkCommitsToModify()
    {
        $commitsToModify = [];
        foreach ($this->commits as $key => &$commit) {
            if ($this->isWorkTime($commit['date'])) {
                if ($key > 0) {
                    $this->makeTheCommitAllowed($commit, $key);
                    $commitsToModify[] = $commit;
                } else {
                    $this->io->warning('commit ' . $commit['commit'] . ' will not be modify because we do not know the previous date (from filter configuration)');
                }
            }
        }
        $this->io->comment(count($commitsToModify) . ' commit(s) to modify');
        dump($commitsToModify);
    }

    private function isWorkTime(DateTime $date)
    {
        $weekDay = $date->format('w');
        if (in_array($weekDay, [1, 2, 3, 4, 5])) {
            $hour = $date->format('H');
            if ($hour >= 9 && $hour <= 19) {
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
        $previousAllowedCommit = $this->getPreviousAllowedCommit($key);
        if (isset($previousAllowedCommit['date_modified'])) {
            $newDate = clone $previousAllowedCommit['date_modified'];
        } else {
            $newDate = clone $previousAllowedCommit['date'];
        }
        /** @var $newDate DateTime */
        $newDate->modify('+10 seconds');
        $commit['date_modified'] = $newDate;
    }

    /**
     * @param $key
     *
     * @return array
     * @throws Exception
     */
    private function getPreviousAllowedCommit($key)
    {
        for ($i = $key; $i <= 0; $i--) {
            $dateKey = 'date';
            if (isset($this->commits[$i]['modified_date'])) {
                return $this->commits[$i];
            } elseif (!$this->isWorkTime($this->commits[$i][$dateKey])) {
                return $this->commits[$i];
            }
        }
        throw new Exception(
            'No previous allowed commit found for this periode (' .
            $this->commits[$key]['commit'] . ', ' . $this->commits[$key]['date']->format('Y-m-d H:i:s') . ', ' .
            $key . ')'
        );
    }
}
