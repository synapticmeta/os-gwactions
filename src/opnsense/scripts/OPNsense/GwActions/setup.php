#!/usr/local/bin/php
<?php

/*
 * Render the Gateway Actions model into /usr/local/etc/gwactions/config.json,
 * which the engine consumes. Invoked via "configctl gwactions configure".
 */

require_once 'config.inc';
require_once 'util.inc';

use OPNsense\GwActions\GwActions;

$mdl = new GwActions();

$out = [
    'enabled' => (string)$mdl->general->enabled === '1',
    'rules' => [],
];

foreach ($mdl->rules->rule->iterateItems() as $uuid => $rule) {
    $action = (string)$rule->action;
    if ($action === 'custom') {
        $action = trim((string)$rule->command);
    }
    $gateways = array_values(array_filter(array_map('trim', explode(',', (string)$rule->gateways))));
    $out['rules'][] = [
        'uuid' => $uuid,
        'enabled' => (string)$rule->enabled === '1',
        'name' => (string)$rule->description,
        'gateways' => $gateways,
        'trigger' => (string)$rule->trigger,
        'action' => $action,
        'delay' => (int)(string)$rule->delay,
        'cooldown' => (int)(string)$rule->cooldown,
    ];
}

@mkdir('/usr/local/etc/gwactions', 0755, true);
$tmp = '/usr/local/etc/gwactions/config.json.tmp';
file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
rename($tmp, '/usr/local/etc/gwactions/config.json');

echo "OK\n";
