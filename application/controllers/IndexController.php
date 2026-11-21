<?php

namespace Icinga\Module\Director_rollback\Controllers;

use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Module\Director\Web\Controller\Extension\CoreApi;
use Icinga\Module\Director\Web\Controller\Extension\DirectorDb;
use Icinga\Module\Director\Web\Window;
use Icinga\Module\Director_rollback\PendingChanges;
use Icinga\Module\Director_rollback\RollbackExecutor;
use Icinga\Web\Controller;
use Icinga\Web\Form;
use Icinga\Web\Notification;
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

        $request = $this->getRequest();
        if ((! $request->isGet() && ! $request->isPost()) || $request->isApiRequest()) {
            $this->getResponse()->setHeader('Allow', 'GET, POST')->setHttpResponseCode(405);
            $this->_helper->viewRenderer->setNoRender();
            return;
        }
        if ($request->isPost()) {
            $this->assertPermission('director_rollback/apply');
        }

        if ($this->Config()->get('frontend', 'disabled', 'no') === 'yes') {
            $this->httpNotFound();
        }

        try {
            $page = (int) $request->getQuery('page', 1);
            $this->view->preview = (new PendingChanges($this->db(), $this->api()))->preview($page);
        } catch (Throwable $exception) {
            Logger::error('Director Rollback preview failed: %s', $exception->getMessage());
            $this->view->preview = null;
        }

        if ($request->isPost() || (($this->view->preview['canApply'] ?? false)
            && $this->hasPermission('director_rollback/apply'))
        ) {
            $form = new Form();
            $form->setName('director_rollback_confirm')
                ->setSubmitLabel($this->translate('Apply full rollback'))
                ->setRedirectUrl($request->getUrl()->without('page'));
            $form->addElement('hidden', 'snapshot_token', [
                'required' => true,
                'value' => $this->view->preview['token'] ?? '',
                'validators' => [['Regex', false, ['pattern' => '/\A[a-f0-9]{64}\z/']]],
            ]);
            $form->setOnSuccess(function (Form $submitted): bool {
                try {
                    (new RollbackExecutor($this->db(), $this->api()))->apply($submitted->getValue('snapshot_token'));
                } catch (Throwable $exception) {
                    Logger::warning('Director rollback aborted: %s', $exception->getMessage());
                    $submitted->addError($this->translate('Rollback aborted. Reload the page and review the current plan.'));
                    $this->getResponse()->setHttpResponseCode(409);
                    return false;
                }

                Notification::success($this->translate('Rollback applied. The active deployment is unchanged.'));
                return true;
            });
            $form->handleRequest($request);
            $this->view->rollbackForm = $form;
        }
    }
}
