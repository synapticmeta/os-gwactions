{#
 # Copyright (c) 2026 synapticmeta
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without
 # modification, are permitted provided that the following conditions are met:
 #
 # 1. Redistributions of source code must retain the above copyright notice,
 #    this list of conditions and the following disclaimer.
 #
 # 2. Redistributions in binary form must reproduce the above copyright
 #    notice, this list of conditions and the following disclaimer in the
 #    documentation and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 # INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 # AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 # AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 # OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #
 # Gateway Actions - main UI
 #}

<script>
    'use strict';

    function ageString(epoch) {
        if (!epoch || epoch <= 0) { return '-'; }
        let secs = Math.floor(Date.now() / 1000) - epoch;
        if (secs < 0) { secs = 0; }
        if (secs < 60) { return secs + 's ago'; }
        if (secs < 3600) { return Math.floor(secs / 60) + 'm ago'; }
        if (secs < 86400) { return Math.floor(secs / 3600) + 'h ago'; }
        return Math.floor(secs / 86400) + 'd ago';
    }

    function gwBadge(status) {
        let s = (status || '').toLowerCase();
        if (s.indexOf('down') !== -1 || s === 'offline') {
            return '<span class="label label-danger">offline</span>';
        }
        return '<span class="label label-success">online</span>';
    }

    function runRule(uuid) {
        if (!uuid) { return; }
        ajaxCall('/api/gwactions/service/runRule/' + uuid, {}, function () {
            refreshStatus();
        });
    }

    function toggleCommandField() {
        let isCustom = $('#rule\\.action').val() === 'custom';
        $('#rule\\.command').closest('tr,.form-group').toggle(isCustom);
    }

    function refreshStatus() {
        ajaxGet('/api/gwactions/service/status', {}, function (data) {
            if (data === undefined || data === null) { return; }

            // gateways table
            let gwRows = '';
            $.each(data.gateways || {}, function (name, status) {
                gwRows += '<tr><td>' + name + '</td><td>' + gwBadge(status) + '</td></tr>';
            });
            $('#gw-body').html(gwRows || '<tr><td colspan="2"><i>no gateways</i></td></tr>');

            // master state
            $('#master-state').html(data.enabled
                ? '<span class="label label-success">enabled</span>'
                : '<span class="label label-default">disabled</span>');

            // history table
            let hRows = '';
            $.each(data.history || [], function (idx, h) {
                let res = h.result || '';
                let cls = res === 'ok' ? 'text-success'
                    : (res === 'debounced' ? 'text-muted' : 'text-danger');
                hRows += '<tr>'
                    + '<td>' + ageString(h.ts) + '</td>'
                    + '<td>' + (h.rule || '') + '</td>'
                    + '<td>' + ((h.affected || []).join(', ') || (h.trigger || '')) + '</td>'
                    + '<td>' + (h.action || '') + '</td>'
                    + '<td class="' + cls + '">' + res + '</td>'
                    + '</tr>';
            });
            $('#hist-body').html(hRows || '<tr><td colspan="5"><i>no events yet</i></td></tr>');
        });

        // wireguard handshakes
        ajaxGet('/api/gwactions/service/wireguard', {}, function (data) {
            let rows = '';
            $.each((data && data.records) || [], function (idx, r) {
                if (r.type !== 'peer') { return; }
                rows += '<tr>'
                    + '<td>' + (r['if'] || '') + '</td>'
                    + '<td>' + (r.endpoint || '') + '</td>'
                    + '<td>' + ageString(parseInt(r['latest-handshake'] || 0)) + '</td>'
                    + '</tr>';
            });
            $('#wg-body').html(rows || '<tr><td colspan="3"><i>no tunnels</i></td></tr>');
        });
    }

    $(document).ready(function () {
        // rules grid
        $('#grid-rules').UIBootgrid({
            search: '/api/gwactions/settings/searchRule',
            get: '/api/gwactions/settings/getRule/',
            set: '/api/gwactions/settings/setRule/',
            add: '/api/gwactions/settings/addRule/',
            del: '/api/gwactions/settings/delRule/',
            toggle: '/api/gwactions/settings/toggleRule/',
            commands: {
                run: {
                    method: function (event) {
                        runRule($(event.currentTarget).data('row-id'));
                    },
                    classname: 'fa fa-fw fa-play',
                    title: '{{ lang._('Run now') }}',
                    sequence: 1
                }
            }
        });

        // show the custom-command field only when Action = Custom
        $(document).on('change', '#rule\\.action', toggleCommandField);
        $('#DialogRule').on('opnsense_bootgrid_mapped', function () {
            setTimeout(toggleCommandField, 50);
        });

        // clear history button
        $('#clearHistory').click(function () {
            ajaxCall('/api/gwactions/service/clearHistory', {}, function () { refreshStatus(); });
        });

        // general settings
        mapDataToFormUI({ 'frm_general': '/api/gwactions/settings/get' }).done(function () {
            $('.selectpicker').selectpicker('refresh');
        });

        $('#saveAct').click(function () {
            saveFormToEndpoint('/api/gwactions/settings/set', 'frm_general', function () {
                ajaxCall('/api/gwactions/service/reconfigure', {}, function () {
                    refreshStatus();
                });
            });
        });

        // regenerate config after rule grid changes
        $('#grid-rules').on('loaded.rs.jquery.bootgrid', function () {
            ajaxCall('/api/gwactions/service/reconfigure', {}, function () { });
        });

        refreshStatus();
        setInterval(refreshStatus, 10000);
    });
</script>

<ul class="nav nav-tabs" role="tablist" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#status">{{ lang._('Status') }}</a></li>
    <li><a data-toggle="tab" href="#rules">{{ lang._('Rules') }}</a></li>
    <li><a data-toggle="tab" href="#settings">{{ lang._('Settings') }}</a></li>
</ul>

<div class="tab-content content-box">

    <!-- STATUS -->
    <div id="status" class="tab-pane fade in active">
        <div class="row">
            <div class="col-md-6">
                <h3 style="display:inline-block; margin-right:10px;">{{ lang._('Engine') }}</h3><span id="master-state"></span>
                <table class="table table-condensed">
                    <thead><tr><th>{{ lang._('Gateway') }}</th><th>{{ lang._('State') }}</th></tr></thead>
                    <tbody id="gw-body"></tbody>
                </table>
                <h3>{{ lang._('WireGuard tunnels') }}</h3>
                <table class="table table-condensed">
                    <thead><tr><th>{{ lang._('Interface') }}</th><th>{{ lang._('Endpoint') }}</th><th>{{ lang._('Last handshake') }}</th></tr></thead>
                    <tbody id="wg-body"></tbody>
                </table>
            </div>
            <div class="col-md-6">
                <h3 style="display:inline-block; margin-right:10px;">{{ lang._('Recent events') }}</h3>
                <button class="btn btn-xs btn-default" id="clearHistory" type="button"><span class="fa fa-fw fa-eraser"></span> {{ lang._('Clear') }}</button>
                <table class="table table-condensed">
                    <thead><tr>
                        <th>{{ lang._('When') }}</th><th>{{ lang._('Rule') }}</th>
                        <th>{{ lang._('Gateways') }}</th><th>{{ lang._('Action') }}</th><th>{{ lang._('Result') }}</th>
                    </tr></thead>
                    <tbody id="hist-body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RULES -->
    <div id="rules" class="tab-pane fade">
        <table id="grid-rules" class="table table-condensed table-hover table-striped" data-editDialog="DialogRule" data-editAlert="ruleChangeMessage">
            <thead>
                <tr>
                    <th data-column-id="enabled" data-type="boolean" data-formatter="rowtoggle">{{ lang._('Enabled') }}</th>
                    <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                    <th data-column-id="gateways" data-type="string">{{ lang._('Gateways') }}</th>
                    <th data-column-id="trigger" data-type="string">{{ lang._('Trigger') }}</th>
                    <th data-column-id="action" data-type="string">{{ lang._('Action') }}</th>
                    <th data-column-id="cooldown" data-type="numeric">{{ lang._('Cooldown') }}</th>
                    <th data-column-id="uuid" data-type="string" data-identifier="true" data-visible="false">{{ lang._('ID') }}</th>
                    <th data-column-id="commands" data-width="13em" data-formatter="commands" data-sortable="false">{{ lang._('Commands') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td>
                        <button data-action="add" type="button" class="btn btn-xs btn-primary"><span class="fa fa-fw fa-plus"></span></button>
                        <button data-action="deleteSelected" type="button" class="btn btn-xs btn-default"><span class="fa fa-fw fa-trash-o"></span></button>
                    </td>
                </tr>
            </tfoot>
        </table>
        <div class="col-md-12">
            <div id="ruleChangeMessage" class="alert alert-info" style="display: none" role="alert">
                {{ lang._('After changing rules the configuration is regenerated automatically.') }}
            </div>
        </div>
    </div>

    <!-- SETTINGS -->
    <div id="settings" class="tab-pane fade">
        {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_general']) }}
        <div class="col-md-12">
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Save') }}</b>
            </button>
        </div>
    </div>

</div>

{{ partial("layout_partials/base_dialog",['fields':dialogRule,'id':'DialogRule','label':lang._('Edit Rule')]) }}
