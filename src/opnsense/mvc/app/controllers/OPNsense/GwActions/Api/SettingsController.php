<?php

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
