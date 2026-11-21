<?php

use Icinga\Module\Director\Db;
use Icinga\Module\Director\Objects\IcingaObject;

function createRollbackFixtures(Db $db, string $prefix): void
{
    $hostTemplate = $prefix . '-host-template';
    $serviceTemplate = $prefix . '-service-template';
    $serviceSet = $prefix . '-set';
    $create = static function (string $type, string $name, string $kind, array $properties) use ($db): void {
        IcingaObject::createByType($type, [
            'object_name' => $name,
            'object_type' => $kind,
        ] + $properties, $db)->store();
    };

    $create('icinga_host', $hostTemplate, 'template', ['check_command' => 'hostalive']);
    $create('icinga_service', $serviceTemplate, 'template', ['check_command' => 'dummy']);
    $create('icinga_service_set', $serviceSet, 'template', ['description' => 'Director rollback demonstration']);
    $create('icinga_service', $prefix . '-set-member', 'object', [
        'service_set' => $serviceSet,
        'imports' => [$serviceTemplate],
    ]);
    foreach ([1, 2] as $number) {
        $host = sprintf('%s-host-%02d', $prefix, $number);
        $properties = ['address' => '192.0.2.' . (150 + $number), 'disabled' => true];
        $properties += $number === 1 ? ['imports' => [$hostTemplate]] : ['check_command' => 'hostalive'];
        $create('icinga_host', $host, 'object', $properties);
        $create('icinga_service', 'demo-check', 'object', [
            'host' => $host,
            'imports' => [$serviceTemplate],
            'disabled' => true,
        ]);
    }
    $create('icinga_service', $prefix . '-apply', 'apply', [
        'imports' => [$serviceTemplate],
        'assign_filter' => 'host.name=' . rawurlencode(json_encode($prefix . '-host-02', JSON_THROW_ON_ERROR)),
        'disabled' => true,
    ]);
}
