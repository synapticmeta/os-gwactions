#!/usr/local/bin/php
<?php

/*
 * Copyright (c) 2026 synapticmeta
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

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
