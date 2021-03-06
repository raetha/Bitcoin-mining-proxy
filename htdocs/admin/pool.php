<?php

/*
 * ./htdocs/admin/pool.php
 *
 * Copyright (C) 2011  Chris Howie <me@chrishowie.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once(dirname(__FILE__) . '/../common.inc.php');
require_once(dirname(__FILE__) . '/../admin/controller.inc.php');
require_once(dirname(__FILE__) . '/../models/pool.inc.php');
require_once(dirname(__FILE__) . '/../views/admin/pool.view.php');

class AdminPoolController extends AdminController
{
    public function indexGetView()
    {
        $pdo = db_connect();

        $rows = db_query($pdo, '
            SELECT
                p.id AS id,
                p.name AS name,
                p.url AS url,
                p.enabled AS enabled,
                COUNT(wp.worker_id) AS worker_count

            FROM pool p

            LEFT OUTER JOIN worker_pool wp
            ON p.id = wp.pool_id

            GROUP BY p.id

            ORDER BY p.name
        ');

        foreach ($rows as $row) {
            $viewdata['pools'][] = new PoolModel($row);
        }

        return new AdminPoolsView($viewdata);
    }

    public function toggleEnabledDefaultView(PoolModel $pool)
    {
        if (!$pool->toggleEnabled()) {
            $_SESSION['tempdata']['errors'][] = 'Unable to toggle that pool.';
        }

        return new RedirectView('/admin/pool.php');
    }

    public function editGetView(PoolModel $pool)
    {
        if (!$pool->refresh()) {
            $_SESSION['tempdata']['errors'][] = 'Pool not found.';
            return new RedirectView('/admin/pool.php');
        }

        return new AdminEditPoolView(array('pool' => $pool));
    }

    public function newGetView()
    {
        return new AdminEditPoolView(array('pool' => new PoolModel()));
    }

    public function editPostView(PoolModel $pool)
    {
        $errors = $pool->validate();

        if ($errors !== TRUE) {
            $_SESSION['tempdata']['errors'] =
                array_merge((array)$_SESSION['tempdata']['errors'], $errors);

            return new AdminEditPoolView(array('pool' => $pool));
        }

        if (!$pool->save()) {
            $_SESSION['tempdata']['errors'] = 'Cannot save pool.  Another pool with the same name may already exist.';

            return new AdminEditPoolView(array('pool' => $pool));
        }

        return new RedirectView('/admin/pool.php');
    }

    public function deleteDefaultView(PoolModel $pool)
    {
        if ($pool->id) {
            $pdo = db_connect();

            $q = $pdo->prepare('
                DELETE p
                FROM pool p

                LEFT OUTER JOIN (
                    SELECT
                        wp.pool_id AS pool_id,
                        COUNT(wp.worker_id) AS workers

                    FROM worker_pool wp

                    GROUP BY wp.pool_id
                ) wp

                ON wp.pool_id = :pool_id

                WHERE p.id = :pool_id_two
                  AND (wp.workers = 0 OR wp.workers IS NULL)
            ');

            $q->execute(array(
                ':pool_id'     => $pool->id,
                ':pool_id_two' => $pool->id));

            if (!$q->rowCount()) {
                $_SESSION['tempdata']['errors'][] = 'Pool still has workers; cannot delete.';
            }
        }

        return new RedirectView('/admin/pool.php');
    }

    public function statsGetView(PoolModel $pool)
    {
        if (!$pool->refresh()) {
            $_SESSION['tempdata']['errors'][] = 'Pool not found.';
            return new RedirectView('/admin/pool.php');
        }

        $viewdata = array('pool' => $pool);

        $pdo = db_connect();

        $viewdata['PoolStatsByHour'] = db_query($pdo, '
            SELECT CONCAT(HOUR(@utc_timestamp:=DATE_SUB(@utc_timestamp,INTERVAL 1 HOUR)),":00") as hour,
                   (SELECT count(*)
                      FROM work_data
                     WHERE HOUR(time_requested) = HOUR(@utc_timestamp)
                       AND DATE(time_requested) = DATE(@utc_timestamp)
                       AND pool_id = :pool_id) as getworks,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE HOUR(time) = HOUR(@utc_timestamp)
                       AND DATE(time) = DATE(@utc_timestamp)
                       AND result = 1
                       AND pool_id = :pool_id) as shares,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE HOUR(time) = HOUR(@utc_timestamp)
                       AND DATE(time) = DATE(@utc_timestamp)
                       AND result = 0
                       AND pool_id = :pool_id) as rejected,
                   (SELECT (shares + rejected) * 4294967296 / 3600 / 1000000) as mhash

              FROM submitted_work, (SELECT @utc_timestamp:=UTC_TIMESTAMP()) t

             WHERE @utc_timestamp > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)

             ORDER BY @utc_timestamp;
        ', array(
            ':pool_id' => $pool->id
        ));

        $viewdata['PoolStatsByDate'] = db_query($pdo, '
            SELECT DATE(@utc_timestamp:=DATE_SUB(@utc_timestamp,INTERVAL 1 DAY)) as date,
                   (SELECT count(*)
                      FROM work_data
                     WHERE DATE(time_requested) = DATE(@utc_timestamp)
                       AND pool_id = :pool_id) as getworks,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE DATE(time) = DATE(@utc_timestamp)
                       AND result = 1
                       AND pool_id = :pool_id) as shares,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE DATE(time) = DATE(@utc_timestamp)
                       AND result = 0
                       AND pool_id = :pool_id) as rejected,
                    (SELECT (shares + rejected) * 4294967296 / 86400 / 1000000) as mhash

              FROM submitted_work, (SELECT @utc_timestamp:=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)) t

             WHERE @utc_timestamp > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MONTH)

             ORDER BY @utc_timestamp;
        ', array(
            ':pool_id' => $pool->id
        ));

        return new AdminPoolStatsView($viewdata);
    }

}

MvcEngine::run(new AdminPoolController());

?>
