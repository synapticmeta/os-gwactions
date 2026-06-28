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

namespace OPNsense\GwActions\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

/**
 * Settings / rule CRUD for Gateway Actions.
 */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'gwactions';
    protected static $internalModelClass = 'OPNsense\GwActions\GwActions';

    public function searchRuleAction()
    {
        return $this->searchBase(
            'rules.rule',
            ['enabled', 'description', 'gateways', 'trigger', 'action', 'cooldown']
        );
    }

    /**
     * The "gateways" field is a free TextField (CSV) because gateways are dynamic.
     * To render it as a working multiselect, the UI's setFormData() expects an
     * options dictionary {name: {value, selected}} rather than a plain string —
     * otherwise editing an existing rule throws (it iterates the string's chars).
     * So we expand the stored CSV into the full option set here.
     */
    public function getRuleAction($uuid = null)
    {
        $result = $this->getBase('rule', 'rules.rule', $uuid);
        if (isset($result['rule']) && array_key_exists('gateways', $result['rule'])) {
            $selected = array_filter(array_map('trim', explode(',', (string)$result['rule']['gateways'])));
            $options = [];
            foreach ($this->gatewayNames() as $name) {
                $options[$name] = [
                    'value' => $name,
                    'selected' => in_array($name, $selected) ? 1 : 0,
                ];
            }
            $result['rule']['gateways'] = $options;
        }
        return $result;
    }

    public function addRuleAction()
    {
        return $this->addBase('rule', 'rules.rule');
    }

    public function setRuleAction($uuid)
    {
        return $this->setBase('rule', 'rules.rule', $uuid);
    }

    public function delRuleAction($uuid)
    {
        return $this->delBase('rules.rule', $uuid);
    }

    public function toggleRuleAction($uuid, $enabled = null)
    {
        return $this->toggleBase('rules.rule', $uuid, $enabled);
    }

    /**
     * @return array sorted list of configured gateway names
     */
    private function gatewayNames()
    {
        $raw = trim((new Backend())->configdRun('interface gateways status'));
        $data = json_decode($raw, true);
        $items = isset($data['items']) ? $data['items'] : $data;
        $names = [];
        foreach (($items ?: []) as $gw) {
            if (!empty($gw['name'])) {
                $names[] = $gw['name'];
            }
        }
        sort($names);
        return $names;
    }
}
