<?php

namespace OPNsense\GwActions\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

/**
 * Runtime/service actions for Gateway Actions: status, gateway list,
 * config regeneration and manual rule execution.
 */
class ServiceController extends ApiControllerBase
{
    public function reconfigureAction()
    {
        if ($this->request->isPost()) {
            $backend = new Backend();
            $backend->configdRun('gwactions configure');
            return ['status' => 'ok'];
        }
        return ['status' => 'failed'];
    }

    public function statusAction()
    {
        $backend = new Backend();
        $raw = trim($backend->configdRun('gwactions status'));
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['enabled' => false, 'rules' => [], 'gateways' => [], 'history' => []];
        }
        return $data;
    }

    /**
     * Return the list of configured gateways for the rule editor multiselect.
     */
    public function gatewaysAction()
    {
        $backend = new Backend();
        $raw = trim($backend->configdRun('interface gateways status'));
        $data = json_decode($raw, true);
        $items = isset($data['items']) ? $data['items'] : $data;
        $rows = [];
        foreach (($items ?: []) as $gw) {
            if (!empty($gw['name'])) {
                $rows[$gw['name']] = $gw['name'];
            }
        }
        return $rows;
    }

    /**
     * Live WireGuard handshake/peer information for the dashboard.
     */
    public function wireguardAction()
    {
        $backend = new Backend();
        $raw = trim($backend->configdRun('wireguard show'));
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['records' => []];
    }

    public function runRuleAction($uuid = null)
    {
        if ($this->request->isPost() && !empty($uuid)) {
            $backend = new Backend();
            $raw = trim($backend->configdRun('gwactions runrule ' . escapeshellarg($uuid)));
            $data = json_decode($raw, true);
            return is_array($data) ? $data : ['status' => 'failed', 'detail' => $raw];
        }
        return ['status' => 'failed'];
    }

    public function clearHistoryAction()
    {
        if ($this->request->isPost()) {
            $backend = new Backend();
            $raw = trim($backend->configdRun('gwactions clearhistory'));
            $data = json_decode($raw, true);
            return is_array($data) ? $data : ['status' => 'ok'];
        }
        return ['status' => 'failed'];
    }
}
