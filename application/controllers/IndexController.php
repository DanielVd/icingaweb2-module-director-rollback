<?php

namespace Icinga\Module\Director_rollback\Controllers;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Module\Director\Web\Controller\Extension\CoreApi;
use Icinga\Module\Director\Web\Controller\Extension\DirectorDb;
use Icinga\Module\Director\Web\Window;
use Icinga\Module\Director_rollback\PendingChanges;
use Icinga\Web\Controller;
use Throwable;

class IndexController extends Controller
{
    use DirectorDb;
    use CoreApi;

    public function Config($file = null)
    {
        return Config::module('director', $file ?? 'config');
    }

    public function Window(): Window
    {
        $id = $this->getRequest()->getHeader('X-Icinga-WindowId');
        return new Window($id === false ? Window::UNDEFINED : $id);
    }

    public function indexAction(): void
    {
        $this->assertPermission('module/director');
        $this->assertPermission('director/audit');

        if (! $this->getRequest()->isGet()) {
            $this->getResponse()->setHeader('Allow', 'GET')->setHttpResponseCode(405);
            $this->_helper->viewRenderer->setNoRender();
            return;
        }

        if ($this->Config()->get('frontend', 'disabled', 'no') === 'yes') {
            $this->httpNotFound();
        }

        try {
            $page = (int) $this->getRequest()->getQuery('page', 1);
            $this->view->preview = (new PendingChanges($this->db(), $this->api()))->preview($page);
        } catch (Throwable $exception) {
            Logger::error('Director Rollback preview failed: %s', $exception->getMessage());
            $this->view->preview = null;
        }
    }
}
