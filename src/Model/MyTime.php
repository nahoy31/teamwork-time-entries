<?php

namespace App\Model;

use TeamWorkPm\Time as BaseTime;

/**
 * Class MyTime
 */
class MyTime extends BaseTime
{
    public function getByTaskList($task_list_id, array $params = [])
    {
        $task_list_id = (int) $task_list_id;

        if ($task_list_id <= 0) {
            throw new \  Exception('Invalid param task_list_id');
        }

        return $this->rest->get("tasklists/$task_list_id/time/total", $params);
    }
}
