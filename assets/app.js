(function () {
    const cfg = window.GncOtchet || {};
    const presetListEl = document.getElementById('presetList');
    const createPresetBtn = document.getElementById('createPresetBtn');

    function request(action, payload) {
        const form = new URLSearchParams();
        form.set('sessid', cfg.sessid || '');
        form.set('action', action);

        Object.keys(payload || {}).forEach(function (key) {
            form.set(key, payload[key]);
        });

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: form.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    
    function renderPresets(items) {
        presetListEl.innerHTML = '';

        if (!items.length) {
            presetListEl.innerHTML = '<div class="gnc-empty">Шаблоны пока не созданы.</div>';
            return;
        }

        items.forEach(function (preset) {
            const item = document.createElement('div');
            item.className = 'gnc-preset-item';

            const left = document.createElement('div');
            const creatorName = String(preset.creatorName || ('#' + String(preset.creatorId || '')));
            const creatorUrl = String(preset.creatorProfileUrl || '');
            const creatorHtml = creatorUrl
                ? '<a href="' + escapeHtml(creatorUrl) + '" target="_blank">' + escapeHtml(creatorName) + '</a>'
                : escapeHtml(creatorName);
            const updatedByDiffers = preset.updatedByDiffers === true;
            const updatedByName = String(preset.updatedByName || '');
            const updatedByUrl = String(preset.updatedByProfileUrl || '');
            const updatedByHtml = updatedByUrl
                ? '<a href="' + escapeHtml(updatedByUrl) + '" target="_blank">' + escapeHtml(updatedByName) + '</a>'
                : escapeHtml(updatedByName);
            const titleHtml = preset.canView !== false
                ? '<a class="gnc-preset-name-link" href="#" data-report-id="' + escapeHtml(preset.id) + '">' + escapeHtml(preset.name) + '</a>'
                : '<span class="gnc-preset-name-link is-disabled">' + escapeHtml(preset.name) + '</span>';
            const updatedMetaHtml = updatedByDiffers
                ? '<div class="gnc-preset-meta">Изменил: ' + updatedByHtml + ' · ' + escapeHtml(preset.updatedAt || '') + '</div>'
                : '<div class="gnc-preset-meta">Обновлен: ' + escapeHtml(preset.updatedAt || '') + '</div>';
            left.innerHTML = '<div class="gnc-preset-name">' + titleHtml + '</div>' +
                updatedMetaHtml +
                '<div class="gnc-preset-meta">Создал: ' + creatorHtml + '</div>';
            const titleLink = left.querySelector('.gnc-preset-name-link[data-report-id]');
            if (titleLink) {
                titleLink.addEventListener('click', function (event) {
                    event.preventDefault();
                    openReportSlider(preset.id);
                });
            }

            const actions = document.createElement('div');
            actions.className = 'gnc-preset-actions';

            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'ui-btn ui-btn-light-border';
            editBtn.textContent = 'Изменить';
            editBtn.addEventListener('click', function () {
                openSlider(preset.id);
            });

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'ui-btn ui-btn-danger';
            deleteBtn.textContent = 'Удалить';
            deleteBtn.addEventListener('click', function () {
                if (!confirm('Удалить шаблон "' + preset.name + '"?')) {
                    return;
                }

                request('deleteTemplate', { id: preset.id }).then(function () {
                    loadTemplates();
                });
            });

            if (preset.canEdit === true) {
                actions.appendChild(editBtn);
            }
            if (preset.canDelete === true) {
                actions.appendChild(deleteBtn);
            }
            item.appendChild(left);
            item.appendChild(actions);
            presetListEl.appendChild(item);
        });
    }

    function loadTemplates() {
        request('listTemplates', {}).then(function (resp) {
            if (resp.status !== 'success') {
                throw new Error('Ошибка загрузки шаблонов');
            }

            renderPresets(resp.data.items || []);
        }).catch(function (e) {
            presetListEl.innerHTML = '<div class="gnc-empty">' + escapeHtml(e.message || 'Ошибка') + '</div>';
        });
    }

    function openSlider(id) {
        const url = cfg.sliderUrl + (id ? ('?id=' + encodeURIComponent(id)) : '');
        window.location.href = url;
    }

    function openReportSlider(id) {
        const url = cfg.reportUrl + '?id=' + encodeURIComponent(id);
        // Open report as standalone page (stable mode for filter UI).
        window.location.href = url;
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    createPresetBtn.addEventListener('click', function () {
        openSlider('');
    });

    loadTemplates();
})();
