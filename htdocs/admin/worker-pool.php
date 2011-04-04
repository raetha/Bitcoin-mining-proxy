<?php

require_once(dirname(__FILE__) . '/../common.inc.php');
require_once(dirname(__FILE__) . '/../admin/controller.inc.php');
require_once(dirname(__FILE__) . '/../views/admin/worker-pool.view.php');

class AdminWorkerPoolController extends AdminController
{
    public function indexGetView()
    {
        $id = (int)$_GET['id'];

        if ($id == 0) {
            return new RedirectView(make_url('/admin/workers.php'));
        }

        $pdo = db_connect();

        $name = db_query($pdo, '
            SELECT name

            FROM worker

            WHERE id = :worker_id
        ', array(':worker_id' => $id));

        if (count($name) == 0) {
            return new RedirectView(make_url('/admin/workers.php'));
        }

        $name = $name[0]['name'];

        $viewdata = array(
            'title'         => "Worker pool management - $name",
            'worker-id'     => $id,
            'worker-name'   => $name
        );

        $viewdata['worker-pools'] = db_query($pdo, '
            SELECT
                wp.pool_id AS `pool-id`,
                wp.pool_username AS username,
                wp.pool_password AS password,
                wp.priority AS priority,
                wp.enabled AS enabled,

                p.name AS pool,
                p.enabled AS `pool-enabled`

            FROM worker_pool wp

            LEFT OUTER JOIN pool p
            ON p.id = wp.pool_id

            WHERE wp.worker_id = :worker_id

            ORDER BY priority DESC 
        ', array(':worker_id' => $id));

        return new AdminWorkerPoolView($viewdata);
    }
}

MvcEngine::run(new AdminWorkerPoolController());

?>