/* Group allocation board: /dashboard/users/group_allocation. */
(function () {
    'use strict';
    var root = document.getElementById('gma');
    if (!root) {
        return;
    }
    var base = root.getAttribute('data-base');
    var token = root.getAttribute('data-token');
    var t = JSON.parse(document.getElementById('gma-i18n').textContent);
    var MAX_GROUPS = 12;
    var picker = document.getElementById('gma-picker');
    var board = document.getElementById('gma-board');
    var emptyNote = document.getElementById('gma-empty');
    var filterInput = document.getElementById('gma-filter');
    var searchInput = document.getElementById('gma-search');
    var results = document.getElementById('gma-results');
    var columns = [];            // [{id, name, canAssign, members}]
    var selection = {};          // gID -> {uID: true}
    var filter = '';
    var groupNames = {};

    Array.prototype.forEach.call(picker.options, function (o) {
        groupNames[o.value] = o.textContent.replace(/\s*\(\d+\)$/, '');
    });

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined) { e.textContent = text; }
        return e;
    }

    function request(method, path, params) {
        var url = base + '/' + path, opts = {method: method, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}};
        var qs = new URLSearchParams();
        Object.keys(params || {}).forEach(function (k) {
            [].concat(params[k]).forEach(function (v) { qs.append(Array.isArray(params[k]) ? k + '[]' : k, v); });
        });
        if (method === 'GET') {
            url += '?' + qs.toString();
        } else {
            qs.append('ccm_token', token);
            opts.body = qs;
        }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {success: false, message: t.failed}; });
        }).then(function (data) {
            if (!data.success) { throw new Error(data.message || t.failed); }
            return data;
        });
    }

    function notify(message, isError) {
        document.getElementById('gma-status').textContent = message;
        var toast = el('div', 'alert alert-' + (isError ? 'danger' : 'success') + ' shadow');
        toast.setAttribute('role', isError ? 'alert' : 'status');
        toast.textContent = message;
        document.getElementById('gma-toast').appendChild(toast);
        setTimeout(function () { toast.remove(); }, isError ? 8000 : 4000);
    }

    function fail(e) { notify(e.message || t.failed, true); }

    function selectedIDs() {
        return Array.prototype.filter.call(picker.options, function (o) { return o.selected; }).map(function (o) { return parseInt(o.value, 10); });
    }

    function findColumn(id) {
        return columns.filter(function (c) { return c.id === id; })[0];
    }

    function matches(m) {
        return !filter || (m.name + ' ' + m.email).toLowerCase().indexOf(filter) !== -1;
    }

    function selectedOf(col) {
        return Object.keys(selection[col.id] || {}).map(Number);
    }

    function apply(action, uIDs, from, to) {
        if (!uIDs.length) { return; }
        var params = {action: action, uIDs: uIDs};
        if (from) { params.from = from; }
        if (to) { params.to = to; }
        request('POST', 'apply', params).then(function (data) {
            data.groups.forEach(function (g) {
                var i = columns.findIndex(function (c) { return c.id === g.id; });
                if (i !== -1) { columns[i] = g; selection[g.id] = {}; }
            });
            render();
            notify(data.message);
            searchNow();
        }).catch(fail);
    }

    function dropOn(col, ev) {
        ev.preventDefault();
        col.el.classList.remove('gma-over');
        var payload;
        try { payload = JSON.parse(ev.dataTransfer.getData('text/plain')); } catch (e) { return; }
        if (!col.canAssign || !payload || !payload.uIDs || payload.from === col.id) { return; }
        var copy = payload.from === 0 || ev.ctrlKey || ev.altKey;
        apply(copy ? 'copy' : 'move', payload.uIDs, copy ? 0 : payload.from, col.id);
    }

    function memberRow(col, m) {
        var checked = !!(selection[col.id] || {})[m.id];
        var row = el('div', 'gma-member' + (checked ? ' gma-selected' : ''));
        row.draggable = true;
        var cb = el('input', 'form-check-input m-0');
        cb.type = 'checkbox';
        cb.checked = checked;
        cb.setAttribute('aria-label', m.name);
        cb.addEventListener('change', function () {
            selection[col.id] = selection[col.id] || {};
            if (cb.checked) { selection[col.id][m.id] = true; } else { delete selection[col.id][m.id]; }
            render();
        });
        row.appendChild(cb);
        var label = el('div', 'gma-name');
        var a = el('a', '', m.name);
        a.href = CCM_APPLICATION_URL + '/index.php/dashboard/users/search/view/' + m.id;
        label.appendChild(a);
        label.appendChild(document.createElement('br'));
        label.appendChild(el('span', 'gma-email', m.email));
        row.appendChild(label);
        if (col.canAssign) {
            var rm = el('button', 'btn btn-sm btn-outline-danger', '×');
            rm.type = 'button';
            rm.title = t.remove;
            rm.setAttribute('aria-label', t.remove + ': ' + m.name);
            rm.addEventListener('click', function () {
                if (window.confirm(t.confirmRemove.replace('%s', m.name).replace('%s', col.name))) {
                    apply('remove', [m.id], col.id, 0);
                }
            });
            row.appendChild(rm);
        }
        row.addEventListener('dragstart', function (ev) {
            var ids = checked ? selectedOf(col) : [m.id];
            ev.dataTransfer.effectAllowed = 'copyMove';
            ev.dataTransfer.setData('text/plain', JSON.stringify({uIDs: ids, from: col.canAssign ? col.id : 0}));
            row.classList.add('gma-dragging');
        });
        row.addEventListener('dragend', function () { row.classList.remove('gma-dragging'); });

        return row;
    }

    function bulkBar(col) {
        var bar = el('div', 'gma-bulk card-footer align-items-center gap-2 flex-wrap');
        var count = selectedOf(col).length;
        bar.appendChild(el('span', 'small', count + ' ' + t.selected));
        var sel = el('select', 'form-select form-select-sm w-auto');
        sel.setAttribute('aria-label', t.moveSelected);
        columns.forEach(function (c) {
            if (c.id !== col.id && c.canAssign) {
                var o = el('option', '', c.name);
                o.value = c.id;
                sel.appendChild(o);
            }
        });
        if (!sel.options.length) { return bar; }
        var move = el('button', 'btn btn-sm btn-primary', t.moveSelected);
        var copy = el('button', 'btn btn-sm btn-outline-secondary', t.copySelected);
        move.type = copy.type = 'button';
        move.disabled = !col.canAssign;
        move.addEventListener('click', function () { apply('move', selectedOf(col), col.id, parseInt(sel.value, 10)); });
        copy.addEventListener('click', function () { apply('copy', selectedOf(col), 0, parseInt(sel.value, 10)); });
        bar.appendChild(sel);
        bar.appendChild(move);
        bar.appendChild(copy);

        return bar;
    }

    function render() {
        board.textContent = '';
        emptyNote.hidden = columns.length > 0;
        columns.forEach(function (col) {
            var shown = col.members.filter(matches);
            var wrap = el('div', 'col');
            var card = el('div', 'card gma-col h-100' + (col.canAssign ? '' : ' gma-readonly') + (selectedOf(col).length ? ' gma-has-selection' : ''));
            col.el = card;
            var head = el('div', 'card-header d-flex justify-content-between align-items-center');
            head.appendChild(el('span', 'fw-bold', col.name));
            head.appendChild(el('span', 'badge text-bg-secondary', String(col.members.length)));
            card.appendChild(head);
            var body = el('div', 'card-body');
            if (!shown.length) {
                body.appendChild(el('p', 'text-muted p-3 mb-0', t.noMembers));
            }
            shown.forEach(function (m) { body.appendChild(memberRow(col, m)); });
            card.appendChild(body);
            if (selectedOf(col).length) { card.appendChild(bulkBar(col)); }
            if (!col.canAssign) {
                card.appendChild(el('div', 'card-footer small text-muted', t.readOnly));
            }
            card.addEventListener('dragover', function (ev) {
                if (col.canAssign) {
                    ev.preventDefault();
                    card.classList.add('gma-over');
                    ev.dataTransfer.dropEffect = (ev.ctrlKey || ev.altKey) ? 'copy' : 'move';
                }
            });
            card.addEventListener('dragleave', function (ev) {
                if (!card.contains(ev.relatedTarget)) { card.classList.remove('gma-over'); }
            });
            card.addEventListener('drop', function (ev) { dropOn(col, ev); });
            wrap.appendChild(card);
            board.appendChild(wrap);
        });
    }

    function loadBoard() {
        var ids = selectedIDs();
        if (ids.length > MAX_GROUPS) {
            ids = ids.slice(0, MAX_GROUPS);
            Array.prototype.forEach.call(picker.options, function (o) { o.selected = ids.indexOf(parseInt(o.value, 10)) !== -1; });
        }
        try {
            var url = new URL(window.location.href);
            if (ids.length) { url.searchParams.set('groups', ids.join(',')); } else { url.searchParams.delete('groups'); }
            window.history.replaceState(null, '', url.toString());
        } catch (e) { /* the board works without a bookmarkable URL */ }
        if (!ids.length) { columns = []; render(); return; }
        request('GET', 'board', {groups: ids.join(',')}).then(function (data) {
            columns = data.groups;
            selection = {};
            render();
            searchNow();
        }).catch(fail);
    }

    var searchTimer;
    function searchNow() {
        var q = searchInput.value.trim();
        results.textContent = '';
        if (q === '') { return; }
        request('GET', 'search', {q: q, groups: columns.map(function (c) { return c.id; }).join(',')}).then(function (data) {
            results.textContent = '';
            if (!data.users.length) { results.appendChild(el('span', 'text-muted', t.noResults)); }
            data.users.forEach(function (u) {
                var chip = el('span', 'badge text-bg-light border gma-chip fw-normal p-2', u.name + ' · ' + u.email);
                chip.draggable = true;
                if (u['in'].length) {
                    chip.title = t.inGroups.replace('%s', u['in'].map(function (id) { return groupNames[id] || id; }).join(', '));
                    chip.appendChild(el('span', 'ms-1', '✓'));
                }
                chip.addEventListener('dragstart', function (ev) {
                    ev.dataTransfer.effectAllowed = 'copy';
                    ev.dataTransfer.setData('text/plain', JSON.stringify({uIDs: [u.id], from: 0}));
                });
                results.appendChild(chip);
            });
        }).catch(fail);
    }

    picker.addEventListener('change', loadBoard);
    filterInput.addEventListener('input', function () { filter = filterInput.value.trim().toLowerCase(); render(); });
    searchInput.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(searchNow, 250); });
    loadBoard();
}());
