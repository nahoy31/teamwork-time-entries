<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

use App\Model\Time;

/**
 * Class TimeExtractCommand
 */
class TimeExtractCommand extends Command
{
    /**
     * @var int
     */
    const CACHE_DURATION = 86400;

    /**
     * @var string
     */
    protected static $defaultName = 'app:time:extract';

    /**
     * @var array
     */
    protected $outputArray = [];

    /**
     * @var string
     */
    protected $cacheDir = '';

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setDescription('Time extraction')
            ->addArgument('tw-api-url', InputArgument::REQUIRED, 'API URL to use')
            ->addArgument('tw-api-key', InputArgument::REQUIRED, 'API Key to use')
            ->addArgument('aws-key', InputArgument::REQUIRED, 'AWS S3 bucket to use')
            ->addArgument('aws-secret', InputArgument::REQUIRED, 'AWS S3 bucket to use')
            ->addArgument('aws-version', InputArgument::REQUIRED, 'AWS S3 bucket to use')
            ->addArgument('aws-region', InputArgument::REQUIRED, 'AWS S3 bucket to use')
            ->addArgument('aws-s3-bucket', InputArgument::REQUIRED, 'AWS S3 bucket to use')
            ->addArgument('destination', InputArgument::REQUIRED, 'AWS S3 bucket to use')
            /**
             * @todo to implement
             */
            ->addOption('current-month', null, InputOption::VALUE_NONE, 'Only data for the current month')
            /**
             * @todo to implement
             */
            ->addOption('last-month', null, InputOption::VALUE_NONE, 'Only data for the last month')
            /**
             * @todo to implement
             */
            ->addOption('all-months', null, InputOption::VALUE_NONE, 'Data for all months')
        ;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cacheDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'cache';

        try {
            \TeamWorkPm\Auth::set(
                $input->getArgument('tw-api-url'),
                $input->getArgument('tw-api-key')
            );

            /**
             * 1. Get all periods
             */
            $periods  = [];
            $date1    = '2019-01-01';
            $date2    = date('Y-m-d');
            $start    = new \DateTime($date1);
            $start->modify('first day of this month');
            $end      = new \DateTime($date2);
            $interval = \DateInterval::createFromDateString('1 month');
            $period   = new \DatePeriod($start, $interval, $end);

            foreach ($period as $dt) {
                $end = $dt->modify('last day of this month');

                $periods[] = [
                    $dt->format('Ym01'),
                    $end->format('Ymd'),
                ];
            }

            /**
             * 2. Get all projects
             */
            $projects = $this->getProjects();

            /**
             * 3. Get all task lists
             */
            $taskLists = [];
            foreach ($projects as $project) {
                $temp = $this->getProjectTaskLists($project);
                foreach ($temp as $row) {
                    $taskLists[$row->id] = $row;
                }
            }

            /**
             * 4. Get all users
             */
            $users = $this->getUsers();

            /**
             * 5. Iterate on the periods
             */
            foreach ($periods as $periodArray) {
                echo sprintf('# [start] period %s - %s', $periodArray[0], $periodArray[1]) . PHP_EOL;

                /**
                 * 6. Get all time entries of the period
                 *
                 * - useful for retrieving only the users who added time during this period
                 * - useful for retrieving only the task lists that have time during this period
                 */
                $taskListsIncluded = $usersIncluded = [];
                $class             = \TeamWorkPm\Factory::build('time');
                $page              = 1;

                while (1) {
                    $result = $class->getAll([
                        'fromDate' => $periodArray[0],
                        'fromTime' => '00:00',
                        'toDate'   => $periodArray[1],
                        'toTime'   => '23:59',
                        'pageSize' => 500,
                        'page'     => $page,
                    ]);
                    $times = json_decode($result, true);

                    if (empty($times)) {
                        break;
                    }

                    foreach ($times as $time) {
                        $taskListsIncluded[] = $time['tasklistId'];
                        $usersIncluded[]     = $time['person-id'];
                    }

                    $page ++;
                }


                $taskListsIncluded = array_unique($taskListsIncluded);
                $usersIncluded     = array_unique($usersIncluded);

                $taskListsFiltered = [];
                foreach ($taskListsIncluded as $id) {
                    if (isset($taskLists[$id])) {
                        $taskListsFiltered[] = $taskLists[$id];
                    } else {
                        // archived project...
                    }
                }

                $usersfiltered = [];
                foreach ($users as $user) {
                    if (in_array($user['id'], $usersIncluded)) {
                        array_push($usersfiltered, $user);
                    }
                }

                /**
                 * 8. Iterate on the task lists
                 */
                foreach ($taskListsIncluded as $taskListId) {
                    if (!$taskListId) {
                        continue;
                    }

                    $cache_file = $this->cacheDir . DIRECTORY_SEPARATOR . 'time_entires_period_' . $periodArray[0] . '_task_list_' . $taskListId;
                    if (file_exists($cache_file) && (filemtime($cache_file) > (time() - self::CACHE_DURATION))) {
                        $result     = file_get_contents($cache_file);
                        $jsonResult = json_decode($result, true);
                        foreach ($jsonResult as $result) {
                            $this->addOutputRow($result['project_name'], $result['task_list_name'], $result['period'], $result['user'], $result['time']);
                        }
                        continue;
                    }

                    /**
                     * 9. Iterate on the users
                     */
                    $periodOutputArray = [];

                    foreach ($usersfiltered as $user) {
                        $timeClass = forward_static_call_array(['\\App\\Model\\MyTime', 'getInstance'], \TeamWorkPm\Auth::get());

                        /**
                         * 10. Get task list user time entries
                         */
                        $result2     = $timeClass->getByTaskList($taskListId, [
                            'fromDate' => $periodArray[0],
                            'fromTime' => '00:00',
                            'toDate'   => $periodArray[1],
                            'toTime'   => '23:59',
                            'userId'   => $user['id'],
                        ]);
                        $jsonResult2 = json_decode($result2, true);
                        $totalTime   = (float) $jsonResult2[0]['tasklist']['time-totals']['total-hours-sum'];
                        $this->addOutputRow($jsonResult2[0]['name'], $jsonResult2[0]['tasklist']['name'], substr($periodArray[0], 0, -2), $user['full-name'], $totalTime);

                        if ($totalTime != 0) {
                            $periodOutputArray[] = [
                                'project_name'   => $jsonResult2[0]['name'],
                                'task_list_name' => $jsonResult2[0]['tasklist']['name'],
                                'period'         => substr($periodArray[0], 0, -2),
                                'user'           => $user['full-name'],
                                'time'           => $totalTime,
                            ];
                        }
                    }

                    file_put_contents($cache_file, json_encode($periodOutputArray), LOCK_EX);
                }
            }
        } catch (\Exception $e) {
            var_dump($e);
            var_dump($e->getMessage());
            var_dump($e->getCode());
        }

        /**
         * 11. Generate the CSV file
         *
         * Example:
         *
         * project_name;task_list_name;period;user;time;
         * Publicar;Gestion de projet;201910;Alain Dupont;27.35
         */
        $tempFile = tempnam(sys_get_temp_dir(), 'Tux');
        $fp = fopen($tempFile, 'w');

        fputcsv($fp, [
                'project_name',
                'task_list_name',
                'period',
                'user',
                'time',
            ],
            "\t"
        );

        foreach ($this->outputArray as $fields) {
            fputcsv($fp, $fields, "\t");
        }

        fclose($fp);

        /**
         * 12. Put the CSV file to AWS S3
         */
        $client = S3Client::factory([
            'version'     => $input->getArgument('aws-version'),
            'region'      => $input->getArgument('aws-region'),
            'credentials' => [
                'key'     => $input->getArgument('aws-key'),
                'secret'  => $input->getArgument('aws-secret'),
            ],
        ]);

        $client->putObject([
            'Bucket'        => $input->getArgument('aws-s3-bucket'),
            'SourceFile'    => $tempFile,
            'Key'           => $input->getArgument('destination'),
        ]);

        unlink($tempFile);
    }

    /**
     * Get all projects
     *
     * @return array
     */
    public function getProjects()
    {
        $class      = \TeamWorkPm\Factory::build('project', ['showMilestones' => false]);
        $cache_file = $this->cacheDir . DIRECTORY_SEPARATOR . 'project';

        if (file_exists($cache_file) && (filemtime($cache_file) > (time() - self::CACHE_DURATION))) {
            $result = file_get_contents($cache_file);
        } else {
            $result = $class->getAll();
            file_put_contents($cache_file, $result, LOCK_EX);
        }

        return json_decode($result, true);
    }

    /**
     * Get all users
     *
     * @return array
     */
    public function getUsers()
    {
        $class      = \TeamWorkPm\Factory::build('people', []);
        $cache_file = $this->cacheDir . DIRECTORY_SEPARATOR . 'people';

        if (file_exists($cache_file) && (filemtime($cache_file) > (time() - self::CACHE_DURATION))) {
            $result = file_get_contents($cache_file);
        } else {
            $result = $class->getAll();
            file_put_contents($cache_file, $result, LOCK_EX);
        }

        $result = json_decode($result, true);

        return $result;
    }

    /**
     * Get task lists of a project
     *
     * @param array $project
     *
     * @return array
     */
    public function getProjectTaskLists($project)
    {
        $class      = \TeamWorkPm\Factory::build('task.list', ['showMilestones' => false]);
        $cache_file = $this->cacheDir . DIRECTORY_SEPARATOR . 'task_list_' . $project['id'];

        if (file_exists($cache_file) && (filemtime($cache_file) > (time() - self::CACHE_DURATION))) {
            $result = file_get_contents($cache_file);
        } else {
            $result = $class->getByProject($project['id']);
            file_put_contents($cache_file, $result, LOCK_EX);
        }

        return json_decode($result);
    }

    /**
     * Add output row
     *
     * @param string $projectName
     * @param string $taskListName
     * @param string $period
     * @param string $username
     * @param float  $time
     *
     * @return false|$this
     */
    public function addOutputRow($projectName, $taskListName, $period, $username, $time)
    {
        if ($time == 0) {
            return false;
        }

        $this->outputArray[] = [
            'project_name'   => $projectName,
            'task_list_name' => $taskListName,
            'period'         => $period,
            'user'           => $username,
            'time'           => $time,
        ];

        echo sprintf('##### [result] %s | %s | %s | %s | %s', $projectName, $taskListName, $period, $username, $time) . PHP_EOL;

        return $this;
    }
}
