<?php

/*
 * ./htdocs/admin/workers.php
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
require_once(dirname(__FILE__) . '/../models/worker.inc.php');
require_once(dirname(__FILE__) . '/../views/admin/workers.view.php');

class AdminWorkersController extends AdminController
{
    public function indexDefaultView()
    {
        $viewdata = array();

        $pdo = db_connect();

        $viewdata['workers'] = db_query($pdo, '
            SELECT
                w.id AS id,
                w.name AS name,
                w.password AS password,
                COUNT(wp.pool_id) AS pools

            FROM worker w

            LEFT OUTER JOIN worker_pool wp
            ON w.id = wp.worker_id

            GROUP BY w.id

            ORDER BY name
        ');

        return new AdminWorkersView($viewdata);
    }

    public function newGetView()
    {
        return new AdminWorkerNewEditView(array('worker' => new WorkerModel()));
    }

    public function newPostView(WorkerModel $worker)
    {
        $result = $worker->validate();
        $valid = $result === TRUE;

        if ($valid) {
            $pdo = db_connect();

            if ($worker->id) {
                $q = $pdo->prepare('
                    UPDATE worker

                    SET name = :name,
                        password = :password

                    WHERE id = :id
                ');

                $q_args = array(
                    ':name'     => $worker->name,
                    ':password' => $worker->password,
                    ':id'       => $worker->id
                );
            } else {
                $q = $pdo->prepare('
                    INSERT INTO worker

                    (name, password)
                        VALUES
                    (:name, :password)
                ');

                $q_args = array(
                    ':name'     => $worker->name,
                    ':password' => $worker->password
                );
            }

            $result = $q->execute($q_args);

            if (!$result) {
                $_SESSION['tempdata']['errors'][] =
                    sprintf('Unable to %s worker.  A worker with the same name probably exists.',
                        $worker->id ? 'modify' : 'create');

                $valid = false;
            }
        } else {
            $_SESSION['tempdata']['errors'] =
                array_merge((array)$_SESSION['tempdata']['errors'], $result);
        }

        if (!$valid) {
            return new AdminWorkerNewEditView(array('worker' => $worker));
        }

        $_SESSION['tempdata']['info'][] = $worker->id ? 'Changes saved.' : 'Worker created.';

        return new RedirectView('/admin/workers.php');
    }

    public function deletePostView($request)
    {
        $id = (int)$request['id'];

        if ($id == 0) {
            $_SESSION['tempdata']['errors'][] = 'Invalid worker ID.';

            return new RedirectView('/admin/workers.php');
        }

        $pdo = db_connect();

        $q = $pdo->prepare('
            SELECT COUNT(*) AS count

            FROM worker_pool wp

            WHERE worker_id = :worker_id
        ');

        $q->execute(array(':worker_id' => $id));

        if ($q->fetchColumn() != 0) {
            $_SESSION['tempdata']['errors'][] = 'Worker must be removed from all pools before it can be deleted.';
            return new RedirectView('/admin/workers.php');
        }

        $q->closeCursor();

        $q = $pdo->prepare('
            DELETE FROM worker WHERE id = :worker_id
        ');

        $q->execute(array(':worker_id' => $id));

        if (!$q->rowCount()) {
            $_SESSION['tempdata']['errors'][] = 'Worker not found.';
            return new RedirectView('/admin/workers.php');
        }

        $q = $pdo->prepare('
            DELETE FROM submitted_work WHERE worker_id = :worker_id
        ');

        $q->execute(array(':worker_id' => $id));

        $q = $pdo->prepare('
            DELETE FROM work_data WHERE worker_id = :worker_id
        ');

        $q->execute(array(':worker_id' => $id));

        $_SESSION['tempdata']['info'][] = 'Worker deleted.';
        return new RedirectView('/admin/workers.php');
    }

    public function editGetView($request)
    {
        $id = (int)$request['id'];

        if ($id == 0) {
            return new RedirectView('/admin/workers.php');
        }

        $pdo = db_connect();

        $q = $pdo->prepare('
            SELECT id, name, password

            FROM worker

            WHERE id = :worker_id
        ');

        $q->execute(array(':worker_id' => $id));

        $row = $q->fetch(PDO::FETCH_ASSOC);

        if ($row === FALSE) {
            $_SESSION['tempdata']['errors'][] = 'Worker not found.';
            return new RedirectView('/admin/workers.php');
        }

        return new AdminWorkerNewEditView(array('worker' => new WorkerModel($row)));
    }

    public function editPostView(WorkerModel $worker)
    {
        return $this->newPostView($worker);
    }

    public function statsGetView($request)
    {
        $id = (int)$request['id'];

        if ($id == 0) {
            return new RedirectView('/admin/workers.php');
        }

        $viewdata = array();

        $pdo = db_connect();

        $q = $pdo->prepare('SELECT name FROM worker WHERE id = :worker_id');
        $q->execute(array(':worker_id' => $id));
        $viewdata['worker'] = $q->fetch(PDO::FETCH_ASSOC);

        $viewdata['WorkerStatsByHour'] = db_query($pdo, '
            SELECT CONCAT(HOUR(@utc_timestamp:=DATE_SUB(@utc_timestamp,INTERVAL 1 HOUR)),":00") as hour,
                   (SELECT count(*)
                      FROM work_data
                     WHERE HOUR(time_requested) = HOUR(@utc_timestamp)
                       AND DATE(time_requested) = DATE(@utc_timestamp)
                       AND worker_id = :worker_id) as getworks,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE HOUR(time) = HOUR(@utc_timestamp)
                       AND DATE(time) = DATE(@utc_timestamp)
                       AND result = 1
                       AND worker_id = :worker_id) as shares,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE HOUR(time) = HOUR(@utc_timestamp)
                       AND DATE(time) = DATE(@utc_timestamp)
                       AND result = 0
                       AND worker_id = :worker_id) as rejected,
                   (SELECT (shares + rejected) * 4294967296 / 3600 / 1000000) as mhash

              FROM submitted_work, (SELECT @utc_timestamp:=UTC_TIMESTAMP()) t

             WHERE @utc_timestamp > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)

             ORDER BY @utc_timestamp;
        ', array(
            ':worker_id' => $id
        ));

        $viewdata['WorkerStatsByDate'] = db_query($pdo, '
            SELECT DATE(@utc_timestamp:=DATE_SUB(@utc_timestamp,INTERVAL 1 DAY)) as date,
                   (SELECT count(*)
                      FROM work_data
                     WHERE DATE(time_requested) = DATE(@utc_timestamp)
                       AND worker_id = :worker_id) as getworks,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE DATE(time) = DATE(@utc_timestamp)
                       AND result = 1
                       AND worker_id = :worker_id) as shares,
                   (SELECT count(result)
                      FROM submitted_work
                     WHERE DATE(time) = DATE(@utc_timestamp)
                       AND result = 0
                       AND worker_id = :worker_id) as rejected,
                    (SELECT (shares + rejected) * 4294967296 / 86400 / 1000000) as mhash

              FROM submitted_work, (SELECT @utc_timestamp:=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)) t

             WHERE @utc_timestamp > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MONTH)

             ORDER BY @utc_timestamp;
        ', array(
            ':worker_id' => $id
        ));

        return new AdminWorkerStatsView($viewdata);
    }
}

MvcEngine::run(new AdminWorkersController());

?>
