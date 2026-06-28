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
