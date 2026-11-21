<?php

namespace Icinga\Module\Director_rollback\Controllers;

use DomainException;
use Icinga\Application\Config;
use Icinga\Application\Logger;
use Icinga\Module\Director\Web\Controller\Extension\CoreApi;
use Icinga\Module\Director\Web\Controller\Extension\DirectorDb;
use Icinga\Module\Director\Web\Window;
use Icinga\Module\Director_rollback\PartialRollbackPlan;
use Icinga\Module\Director_rollback\PendingChanges;
use Icinga\Module\Director_rollback\RollbackExecutor;
use Icinga\Module\Director_rollback\RollbackPlan;
use Icinga\Web\Controller;
use Icinga\Web\Form;
use Icinga\Web\Notification;
use Icinga\Web\Url;
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
        if (! $this->checkAccess()) {
            return;
        }
        $request = $this->getRequest();

        try {
            $page = (int) $request->getQuery('page', 1);
            $this->view->preview = (new PendingChanges($this->db(), $this->api()))->preview($page);
        } catch (Throwable $exception) {
            Logger::error('Director Rollback preview failed: %s', $exception->getMessage());
            $this->view->preview = null;
        }

        if ($this->hasPermission('director_rollback/apply') && ($this->view->preview['canSelect'] ?? false)) {
            $this->view->partialUrl = $this->rollbackUrl('director_rollback/index/partial');
        }

        if ($request->isPost() || (($this->view->preview['canApply'] ?? false)
            && $this->hasPermission('director_rollback/apply'))
        ) {
            $this->view->rollbackForm = $this->confirmationForm($this->view->preview['token'] ?? '');
        }
    }

    public function partialAction(): void
    {
        if (! $this->checkAccess()) {
            return;
        }
        $this->assertPermission('director_rollback/apply');
        $request = $this->getRequest();
        $this->view->overviewUrl = $this->rollbackUrl('director_rollback');
        $this->view->selectionUrl = $this->rollbackUrl('director_rollback/index/partial');

        try {
            $pending = new PendingChanges($this->db(), $this->api());
            $snapshot = $pending->snapshot();
            $plan = RollbackPlan::fromActivities($pending->activities($snapshot));
            $this->view->choices = array_filter($plan, PartialRollbackPlan::isSelectable(...));
            $this->view->deploymentId = $snapshot['deploymentId'];

            $confirming = $request->isPost() && $request->getPost('selection') !== null;
            $raw = $confirming ? $request->getPost('selection') : $request->getPost('objects', []);
            if ($confirming && is_string($raw)) {
                $raw = explode(',', $raw);
            }
            if (! is_array($raw)) {
                throw new DomainException('Invalid selection.');
            }
            if ($request->isPost()) {
                $selection = (new PartialRollbackPlan($this->db()))->select($snapshot, $plan, $raw);
                $this->view->selection = $selection;
                $this->view->rollbackForm = $this->confirmationForm(
                    $selection['token'],
                    $selection['requested'],
                    $confirming
                );
            }
        } catch (DomainException $exception) {
            $this->getResponse()->setHttpResponseCode(409);
            $this->view->error = $this->translate($exception->getMessage());
        } catch (Throwable $exception) {
            Logger::error('Director partial rollback preview failed: %s', $exception->getMessage());
            $this->getResponse()->setHttpResponseCode(409);
            $this->view->error = $this->translate('The partial rollback plan could not be verified.');
        }
    }

    private function checkAccess(): bool
    {
        $this->assertPermission('module/director');
        $this->assertPermission('director/audit');

        $request = $this->getRequest();
        if ((! $request->isGet() && ! $request->isPost()) || $request->isApiRequest()) {
            $this->getResponse()->setHeader('Allow', 'GET, POST')->setHttpResponseCode(405);
            $this->_helper->viewRenderer->setNoRender();
            return false;
        }
        if ($request->isPost()) {
            $this->assertPermission('director_rollback/apply');
        }

        if ($this->Config()->get('frontend', 'disabled', 'no') === 'yes') {
            $this->httpNotFound();
        }

        return true;
    }

    private function rollbackUrl(string $path): Url
    {
        $resource = $this->getRequest()->getQuery('dbResourceName');
        return Url::fromPath($path, is_string($resource) ? ['dbResourceName' => $resource] : []);
    }

    private function confirmationForm(string $token, ?array $requested = null, bool $handle = true): Form
    {
        $partial = $requested !== null;
        $form = new Form();
        $form->setName($partial ? 'director_rollback_partial' : 'director_rollback_confirm')
            ->setSubmitLabel($partial ? $this->translate('Apply partial rollback') : $this->translate('Apply full rollback'))
            ->setRedirectUrl($this->rollbackUrl('director_rollback'));
        $form->addElement('hidden', 'snapshot_token', [
            'required' => true,
            'value' => $token,
            'validators' => [['Regex', false, ['pattern' => '/\A[a-f0-9]{64}\z/']]],
        ]);
        if ($partial) {
            $form->setAction($this->rollbackUrl('director_rollback/index/partial'));
            $form->addElement('hidden', 'selection', [
                'required' => true,
                'value' => implode(',', $requested),
                'validators' => [['Regex', false, ['pattern' => '/\A[1-9][0-9]*(?:,[1-9][0-9]*)*\z/']]],
            ]);
        } else {
            $form->setAttrib('class', trim($form->getAttrib('class') . ' inline'));
        }
        $form->setOnSuccess(function (Form $submitted) use ($partial): bool {
            try {
                $selection = $partial ? explode(',', $submitted->getValue('selection')) : null;
                (new RollbackExecutor($this->db(), $this->api()))->apply($submitted->getValue('snapshot_token'), $selection);
            } catch (Throwable $exception) {
                Logger::warning('Director rollback aborted: %s', $exception->getMessage());
                $submitted->addError($this->translate('Rollback aborted. Reload the page and review the current plan.'));
                $this->getResponse()->setHttpResponseCode(409);
                return false;
            }

            Notification::success($this->translate('Rollback applied. The active deployment is unchanged.'));
            return true;
        });
        $form->setRequest($this->getRequest());
        if ($handle) {
            $form->handleRequest($this->getRequest());
        }
        return $form;
    }
}
