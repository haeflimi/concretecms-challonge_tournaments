/**
 * Challonge Tournaments, built-in UI: the result forms post by AJAX (then the page reloads to show the new state),
 * the overview block polls /ccm/challonge_tournaments/state/<id> and reloads when the tournament changed (no
 * Challonge request is involved; the state is what the last sync stored). Dashboard: the seeding list is sortable.
 */
(function () {
    'use strict';

    function message(container, type, text) {
        var box = container ? container.querySelector('.ct-messages') : null;
        if (!box) {
            window.alert(text);
            return;
        }
        var alert = document.createElement('div');
        // a theme template may name its own notice classes (data-notice-success / data-notice-error on the block)
        var themed = container.dataset[type === 'success' ? 'noticeSuccess' : 'noticeError'];
        if (themed) {
            alert.className = themed;
            var body = document.createElement('p');
            body.className = 'tc-notice-body';
            body.textContent = text;
            alert.appendChild(body);
        } else {
            alert.className = 'alert alert-' + (type === 'success' ? 'success' : 'danger') + ' alert-dismissible';
            alert.textContent = text;
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn-close';
            close.setAttribute('data-bs-dismiss', 'alert');
            alert.appendChild(close);
        }
        box.innerHTML = '';
        box.appendChild(alert);
        box.scrollIntoView({block: 'nearest'});
    }

    function submitAjax(form) {
        var block = form.closest('.ct-block');
        var button = form.querySelector('button[type="submit"], button:not([type])');
        if (button) {
            button.disabled = true;
        }
        var body = new FormData(form);
        window.fetch(form.action, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        }).then(function (response) {
            return response.json().then(function (data) {
                return {ok: response.ok, data: data};
            });
        }).then(function (result) {
            if (result.ok && result.data.success) {
                message(block, 'success', result.data.message || '');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            } else {
                message(block, 'error', (result.data && result.data.message) || 'Error');
                if (button) {
                    button.disabled = false;
                }
            }
        }).catch(function () {
            // no JSON (e.g. a redirect to the login): plain submit
            form.classList.remove('ct-ajax');
            form.submit();
        });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('ct-ajax')) {
            return;
        }
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            e.preventDefault();
            return;
        }
        e.preventDefault();
        submitAjax(form);
    });

    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('.ct-toggle-report');
        if (toggle) {
            e.preventDefault();
            var target = document.querySelector(toggle.dataset.target);
            if (target) {
                target.style.display = target.style.display === 'none' ? '' : 'none';
                var input = target.querySelector('input[name="scores"]');
                if (input && target.style.display !== 'none') {
                    input.focus();
                }
            }
            return;
        }
        var fill = e.target.closest('.ct-fill-reference');
        if (fill) {
            e.preventDefault();
            var ref = document.getElementById('ct-reference');
            if (ref) {
                ref.value = fill.dataset.reference;
                ref.focus();
            }
        }
    });

    // polling of the overview blocks
    function poll(block) {
        var seconds = parseInt(block.dataset.refresh, 10) || 0;
        if (seconds < 10) {
            return;
        }
        var base = block.dataset.actionBase;
        var panes = block.querySelectorAll('.ct-tournament');
        if (!panes.length) {
            return;
        }
        var timer = window.setInterval(function () {
            if (document.hidden) {
                return;
            }
            // only the visible tournament
            var pane = block.querySelector('.ct-tournament.active') || panes[0];
            var id = pane.dataset.tournament;
            window.fetch(base + '/state/' + id, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (r) {
                    return r.ok ? r.json() : null;
                })
                .then(function (data) {
                    if (!data || !data.success || !data.tournament) {
                        return;
                    }
                    var changed = data.tournament.lastChangedAt || '';
                    if (changed !== (pane.dataset.changed || '') && !block.querySelector('.ct-report-form:not([style*="display: none"]) input:focus')) {
                        window.clearInterval(timer);
                        window.location.reload();
                    }
                })
                .catch(function () {});
        }, seconds * 1000);
    }

    // dashboard: drag to order the seeding list
    function sortable(list) {
        var dragging = null;
        list.addEventListener('dragstart', function (e) {
            dragging = e.target.closest('li');
            if (dragging) {
                dragging.classList.add('ct-dragging');
                e.dataTransfer.effectAllowed = 'move';
            }
        });
        list.addEventListener('dragend', function () {
            if (dragging) {
                dragging.classList.remove('ct-dragging');
            }
            dragging = null;
        });
        list.addEventListener('dragover', function (e) {
            if (!dragging) {
                return;
            }
            e.preventDefault();
            var over = e.target.closest('li');
            if (!over || over === dragging) {
                return;
            }
            var rect = over.getBoundingClientRect();
            var after = (e.clientY - rect.top) > rect.height / 2;
            list.insertBefore(dragging, after ? over.nextSibling : over);
            var manual = document.getElementById('ct-order-manual');
            if (manual) {
                manual.checked = true;
            }
        });
    }

    function init() {
        document.querySelectorAll('.ct-tournament-overview').forEach(poll);
        document.querySelectorAll('.ct-sortable').forEach(sortable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
