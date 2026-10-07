/* Member manager on /dashboard/users/groups/edit/<id>. Talks to the JSON routes of the group_member_manager package. */
(function ($) {
    'use strict';
    var match = window.location.pathname.match(/\/dashboard\/users\/groups\/edit\/(\d+)/);
    var $anchor = $('#update-group-form');
    if (!match || !$anchor.length) {
        return;
    }
    var base = CCM_APPLICATION_URL + '/index.php/ccm/group_member_manager/';
    var gID = match[1];
    var token = window.ccmGroupMemberManagerToken;
    var state = {q: '', page: 1};

    var $panel = $('<div class="gmm-panel"><fieldset><legend></legend>' +
        '<div class="gmm-alert"></div>' +
        '<div class="gmm-add mb-4" hidden><label class="form-label"></label>' +
        '<div class="gmm-candidates"><input type="text" class="form-control gmm-candidate-input" autocomplete="off">' +
        '<div class="list-group gmm-candidate-list" hidden></div></div></div>' +
        '<input type="search" class="form-control gmm-search mb-3">' +
        '<table class="table table-striped"><thead><tr><th></th><th></th><th></th><th></th></tr></thead><tbody></tbody></table>' +
        '<div class="gmm-pager d-flex justify-content-between align-items-center"></div>' +
        '</fieldset></div>');
    var t = window.ccmGroupMemberManagerText || {};
    $panel.find('legend').text(t.title || 'Members');
    $panel.find('.gmm-add label').text(t.addLabel || 'Add a member');
    $panel.find('.gmm-candidate-input').attr('placeholder', t.addPlaceholder || 'Search users by name or email');
    $panel.find('.gmm-search').attr('placeholder', t.filter || 'Filter members');
    $panel.find('thead th').each(function (i) { $(this).text([t.name || 'Name', t.email || 'Email', t.entered || 'Added', ''][i]); });
    $anchor.after($panel);

    function alertMsg(msg, type) {
        var $a = $panel.find('.gmm-alert').empty();
        if (msg) {
            $('<div class="alert"></div>').addClass('alert-' + (type || 'danger')).text(msg).appendTo($a);
        }
    }

    function api(method, path, data) {
        return $.ajax({url: base + path, method: method, data: data, dataType: 'json'}).fail(function (xhr) {
            alertMsg((xhr.responseJSON && xhr.responseJSON.error) || 'Request failed');
        });
    }

    function mutate(action, uID) {
        return api('POST', action + '/' + gID, {uID: uID, ccm_token: token}).done(function () {
            alertMsg('');
            load();
        });
    }

    function load() {
        api('GET', 'members/' + gID, {q: state.q, page: state.page}).done(function (r) {
            $panel.find('.gmm-add').prop('hidden', !r.canAssign);
            var $tbody = $panel.find('tbody').empty();
            if (!r.members.length) {
                $('<tr><td colspan="4" class="text-muted"></td></tr>').find('td').text(t.empty || 'No members.').end().appendTo($tbody);
            }
            $.each(r.members, function (i, m) {
                var $row = $('<tr><td></td><td class="gmm-email"></td><td></td><td class="text-end"></td></tr>');
                $row.children().eq(0).append($('<a>').attr('href', CCM_APPLICATION_URL + '/index.php/dashboard/users/search/view/' + m.id).text(m.name));
                $row.children().eq(1).text(m.email);
                $row.children().eq(2).text(m.entered || '');
                if (r.canAssign) {
                    $('<button type="button" class="btn btn-sm btn-outline-danger"></button>').text(t.remove || 'Remove').on('click', function () {
                        if (window.confirm((t.confirmRemove || 'Remove %s from this group?').replace('%s', m.name))) {
                            mutate('remove', m.id);
                        }
                    }).appendTo($row.children().eq(3));
                }
                $tbody.append($row);
            });
            var $pager = $panel.find('.gmm-pager').empty();
            $('<span class="text-muted"></span>').text(r.total + ' ' + (t.total || 'members')).appendTo($pager);
            if (r.pages > 1) {
                var $nav = $('<div class="btn-group"></div>').appendTo($pager);
                $('<button type="button" class="btn btn-sm btn-outline-secondary">&laquo;</button>').prop('disabled', r.page <= 1).on('click', function () { state.page--; load(); }).appendTo($nav);
                $('<span class="btn btn-sm disabled"></span>').text(r.page + ' / ' + r.pages).appendTo($nav);
                $('<button type="button" class="btn btn-sm btn-outline-secondary">&raquo;</button>').prop('disabled', r.page >= r.pages).on('click', function () { state.page++; load(); }).appendTo($nav);
            }
        });
    }

    function debounce(fn, ms) {
        var timer;
        return function () {
            var args = arguments, self = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    $panel.find('.gmm-search').on('input', debounce(function () {
        state.q = $.trim($(this).val());
        state.page = 1;
        load();
    }, 250));

    var $input = $panel.find('.gmm-candidate-input');
    var $list = $panel.find('.gmm-candidate-list');
    $input.on('input', debounce(function () {
        var q = $.trim($input.val());
        $list.empty().prop('hidden', true);
        if (q === '') {
            return;
        }
        api('GET', 'candidates/' + gID, {q: q}).done(function (users) {
            $.each(users, function (i, u) {
                $('<button type="button" class="list-group-item list-group-item-action"></button>')
                    .append($('<span>').text(u.name), ' ', $('<small class="gmm-email">').text(u.email))
                    .on('click', function () {
                        $list.empty().prop('hidden', true);
                        $input.val('');
                        mutate('add', u.id);
                    }).appendTo($list);
            });
            if (!users.length) {
                $('<div class="list-group-item text-muted"></div>').text(t.noResults || 'No matching users outside this group.').appendTo($list);
            }
            $list.prop('hidden', false);
        });
    }, 250));
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.gmm-candidates').length) {
            $list.prop('hidden', true);
        }
    });

    load();
}(jQuery));
