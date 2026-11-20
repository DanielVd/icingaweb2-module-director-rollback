<?php

use Icinga\Application\Icinga;
use Icinga\Authentication\Auth;

$app = Icinga::app();
if (! $app->isWeb()) {
    return;
}

$auth = Auth::getInstance();
$manager = $app->getModuleManager();
if (! $manager->hasLoaded('director')
    || ! $auth->hasPermission('module/director')
    || ! $auth->hasPermission('director/audit')
) {
    return;
}

// Director can change its menu section name when a different database is selected.
foreach ($manager->getModule('director')->getMenu() as $item) {
    $url = $item->getUrl();
    if ($url !== null && $url->getPath() === 'director') {
        $this->menuSection($item->getName())
            ->setUrl('director')
            ->add(N_('Rollback'))
            ->setUrl('director_rollback')
            ->setPriority(903)
            ->setPermission('director/audit');
        break;
    }
}
