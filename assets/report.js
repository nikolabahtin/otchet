(function () {
    const cfg = window.GncOtchetReport || {};
    const titleEl = document.getElementById('reportTitle');
    const metaEl = document.getElementById('reportMeta');
    const filtersWrap = document.getElementById('reportFilters');
    const buildReportBtn = document.getElementById('buildReportBtn');
    const headerRow = document.getElementById('reportHeaderRow');
    const sourceRow = document.getElementById('reportSourceRow');
    const sampleRow = document.getElementById('reportSampleRow');

    const DATE_PRESETS = [
        { value: 'ANY', label: 'Любая дата' },
        { value: 'YESTERDAY', label: 'Вчера' },
        { value: 'TODAY', label: 'Сегодня' },
        { value: 'TOMORROW', label: 'Завтра' },
        { value: 'CURRENT_WEEK', label: 'Текущая неделя' },
        { value: 'LAST_WEEK', label: 'Прошлая неделя' },
        { value: 'NEXT_WEEK', label: 'Следующая неделя' },
        { value: 'CURRENT_MONTH', label: 'Текущий месяц' },
        { value: 'LAST_MONTH', label: 'Прошлый месяц' },
        { value: 'NEXT_MONTH', label: 'Следующий месяц' },
        { value: 'CURRENT_QUARTER', label: 'Текущий квартал' },
        { value: 'LAST_7_DAYS', label: 'Последние 7 дней' },
        { value: 'LAST_30_DAYS', label: 'Последние 30 дней' },
        { value: 'LAST_60_DAYS', label: 'Последние 60 дней' },
        { value: 'LAST_90_DAYS', label: 'Последние 90 дней' },
        { value: 'LAST_N_DAYS', label: 'Последние N дней' },
        { value: 'NEXT_N_DAYS', label: 'Следующие N дней' },
        { value: 'YEAR', label: 'Год' },
        { value: 'EXACT', label: 'Точная дата' },
        { value: 'RANGE', label: 'Диапазон' }
    ];

    let currentConfig = {};

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

    function countLevel(node, map) {
        let lvl = 1;
        let parentId = node.parentId;
        while (parentId && map[parentId]) {
            lvl += 1;
            parentId = map[parentId].parentId;
        }
        return lvl;
    }

    function collectColumns(config, entityMetaMap) {
        const nodes = Array.isArray(config.nodes) ? config.nodes : [];
        const nodeMap = {};
        nodes.forEach(function (node) {
            nodeMap[node.id] = node;
        });

        const rows = [];
        nodes.forEach(function (node) {
            const entityMeta = entityMetaMap[node.entityCode] || {};
            const fields = Array.isArray(entityMeta.fields) ? entityMeta.fields : [];
            const fieldMap = {};
            fields.forEach(function (f) {
                fieldMap[String(f.code)] = f;
            });

            const selected = Array.isArray(node.selectedFields) ? node.selectedFields : [];
            selected.forEach(function (fieldCode) {
                const field = fieldMap[fieldCode] || {};
                rows.push({
                    key: String(node.id) + '::' + String(fieldCode),
                    fieldTitle: String(field.title || fieldCode),
                    sourceTitle: String(node.entityTitle || (entityMeta.entity && entityMeta.entity.title) || node.entityCode || ''),
                    level: countLevel(node, nodeMap)
                });
            });
        });

        const order = Array.isArray(config.columnOrder) ? config.columnOrder : [];
        if (!order.length) {
            return rows;
        }

        const byKey = {};
        rows.forEach(function (r) { byKey[r.key] = r; });

        const ordered = [];
        order.forEach(function (key) {
            if (byKey[key]) {
                ordered.push(byKey[key]);
                delete byKey[key];
            }
        });
        Object.keys(byKey).forEach(function (k) { ordered.push(byKey[k]); });
        return ordered;
    }

    function renderColumns(columns) {
        if (!columns.length) {
            headerRow.innerHTML = '<th class="gnc-empty" colspan="1">В шаблоне нет выбранных полей.</th>';
            sourceRow.innerHTML = '<td class="gnc-empty">Источник колонок</td>';
            sampleRow.innerHTML = '<td class="gnc-empty">Пример данных</td>';
            return;
        }

        headerRow.innerHTML = '';
        sourceRow.innerHTML = '';
        sampleRow.innerHTML = '';

        columns.forEach(function (col) {
            const th = document.createElement('th');
            th.textContent = col.fieldTitle;

            const src = document.createElement('td');
            src.className = 'gnc-col-source';
            src.textContent = col.sourceTitle + ' / Уровень ' + col.level;

            const sample = document.createElement('td');
            sample.textContent = '...';

            headerRow.appendChild(th);
            sourceRow.appendChild(src);
            sampleRow.appendChild(sample);
        });
    }

    function detectFilterType(fieldMeta) {
        const type = String((fieldMeta && fieldMeta.type) || '').toLowerCase();
        if (Array.isArray(fieldMeta && fieldMeta.enumItems) && fieldMeta.enumItems.length > 0) {
            return 'enum';
        }
        if (fieldMeta && fieldMeta.isLink) {
            return 'link';
        }
        if (fieldMeta && fieldMeta.isDate) {
            return 'date';
        }
        if (type === 'boolean') {
            return 'boolean';
        }
        if (type === 'integer' || type === 'double' || type === 'float' || type === 'money') {
            return 'number';
        }
        return 'text';
    }

    function addOption(select, value, title) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = title;
        select.appendChild(option);
    }

    function mapEntityCodeToCrmEntityTypeId(entityCode) {
        const code = String(entityCode || '').toUpperCase();
        if (code === 'LEAD') { return 1; }
        if (code === 'DEAL') { return 2; }
        if (code === 'CONTACT') { return 3; }
        if (code === 'COMPANY') { return 4; }
        if (code.indexOf('DYNAMIC_') === 0) {
            const dynamicId = parseInt(code.slice(8), 10);
            return Number.isFinite(dynamicId) && dynamicId > 0 ? dynamicId : 0;
        }
        return 0;
    }

    function buildCrmEntitiesForSelector(filter) {
        const targets = Array.isArray(filter.linkTargets) ? filter.linkTargets.filter(Boolean) : [];
        const entities = [];
        targets.forEach(function (target) {
            const typeId = mapEntityCodeToCrmEntityTypeId(target);
            if (typeId > 0) {
                entities.push({
                    id: 'crm',
                    options: { entityTypeId: typeId }
                });
            }
        });

        if (!entities.length) {
            entities.push({ id: 'crm' });
        }
        return entities;
    }

    function collectFilterFields(config, entityMetaMap) {
        const nodeMap = {};
        (config.nodes || []).forEach(function (node) {
            nodeMap[String(node.id)] = node;
        });

        const list = Array.isArray(config.filterFields) ? config.filterFields : [];
        return list.map(function (item) {
            const nodeId = String(item.nodeId || '');
            const fieldCode = String(item.fieldCode || '');
            const key = String(item.key || (nodeId + '::' + fieldCode));
            const node = nodeMap[nodeId] || {};
            const entityCode = String(item.entityCode || node.entityCode || '');
            const entityMeta = entityMetaMap[entityCode] || {};
            const fieldMeta = (Array.isArray(entityMeta.fields) ? entityMeta.fields : []).find(function (f) {
                return String(f.code) === fieldCode;
            }) || {};

            return {
                key: key,
                nodeId: nodeId,
                entityCode: entityCode,
                entityTitle: String(item.entityTitle || node.entityTitle || (entityMeta.entity && entityMeta.entity.title) || entityCode),
                fieldCode: fieldCode,
                fieldTitle: String(item.fieldTitle || fieldMeta.title || fieldCode),
                type: detectFilterType(fieldMeta),
                isDate: !!fieldMeta.isDate,
                isLink: !!fieldMeta.isLink,
                isMultiple: !!fieldMeta.isMultiple,
                linkTargets: Array.isArray(fieldMeta.linkTargets) ? fieldMeta.linkTargets : [],
                enumItems: Array.isArray(fieldMeta.enumItems) ? fieldMeta.enumItems : [],
                value: '',
                valueTitle: ''
            };
        }).filter(function (f) {
            return !!f.fieldCode;
        });
    }

    function createDateFilterControl(filter) {
        const wrap = document.createElement('div');
        wrap.className = 'gnc-date-filter';

        filter.value = filter.value && typeof filter.value === 'object' ? filter.value : { mode: 'ANY' };

        const modeSelect = document.createElement('select');
        modeSelect.className = 'gnc-col-filter';
        DATE_PRESETS.forEach(function (preset) {
            addOption(modeSelect, preset.value, preset.label);
        });
        modeSelect.value = filter.value.mode || 'ANY';

        const extra = document.createElement('div');
        extra.className = 'gnc-date-filter-extra';

        function renderExtra() {
            const mode = modeSelect.value;
            filter.value.mode = mode;
            extra.innerHTML = '';

            if (mode === 'LAST_N_DAYS' || mode === 'NEXT_N_DAYS') {
                const input = document.createElement('input');
                input.type = 'number';
                input.min = '1';
                input.className = 'gnc-col-filter';
                input.placeholder = 'Количество дней';
                input.value = String(filter.value.days || '7');
                input.addEventListener('input', function () {
                    filter.value.days = parseInt(input.value || '0', 10) || 0;
                });
                extra.appendChild(input);
            }

            if (mode === 'EXACT') {
                const input = document.createElement('input');
                input.type = 'date';
                input.className = 'gnc-col-filter';
                input.value = String(filter.value.date || '');
                input.addEventListener('change', function () {
                    filter.value.date = input.value;
                });
                extra.appendChild(input);
            }

            if (mode === 'RANGE') {
                const from = document.createElement('input');
                from.type = 'date';
                from.className = 'gnc-col-filter';
                from.placeholder = 'С';
                from.value = String(filter.value.from || '');
                from.addEventListener('change', function () {
                    filter.value.from = from.value;
                });

                const to = document.createElement('input');
                to.type = 'date';
                to.className = 'gnc-col-filter';
                to.placeholder = 'По';
                to.value = String(filter.value.to || '');
                to.addEventListener('change', function () {
                    filter.value.to = to.value;
                });

                extra.appendChild(from);
                extra.appendChild(to);
            }
        }

        modeSelect.addEventListener('change', renderExtra);
        wrap.appendChild(modeSelect);
        wrap.appendChild(extra);
        renderExtra();

        return wrap;
    }

    function createEnumFilterControl(filter) {
        const select = document.createElement('select');
        select.className = 'gnc-col-filter';

        if (filter.isMultiple) {
            select.multiple = true;
            select.size = Math.min(8, Math.max(4, (filter.enumItems || []).length));
            (filter.enumItems || []).forEach(function (item) {
                addOption(select, String(item.id || ''), String(item.title || item.id || ''));
            });
            filter.value = Array.isArray(filter.value) ? filter.value : [];
            Array.from(select.options).forEach(function (opt) {
                opt.selected = filter.value.indexOf(opt.value) >= 0;
            });
            select.addEventListener('change', function () {
                filter.value = Array.from(select.selectedOptions).map(function (opt) { return opt.value; });
            });
            return select;
        }

        addOption(select, '', 'Любое значение');
        (filter.enumItems || []).forEach(function (item) {
            addOption(select, String(item.id || ''), String(item.title || item.id || ''));
        });
        select.value = String(filter.value || '');
        select.addEventListener('change', function () {
            filter.value = select.value;
        });
        return select;
    }

    function createLinkSearchControl(filter) {
        const wrap = document.createElement('div');
        wrap.className = 'gnc-link-filter';

        const selectorNode = document.createElement('div');
        wrap.appendChild(selectorNode);

        if (!(window.BX && BX.UI && BX.UI.EntitySelector && BX.UI.EntitySelector.TagSelector)) {
            const fallback = document.createElement('input');
            fallback.type = 'text';
            fallback.className = 'gnc-col-filter';
            fallback.placeholder = 'EntitySelector не загружен';
            fallback.addEventListener('input', function () {
                filter.value = fallback.value;
                filter.valueTitle = fallback.value;
            });
            wrap.appendChild(fallback);
            return wrap;
        }

        const entities = buildCrmEntitiesForSelector(filter);
        const primaryEntityCode = Array.isArray(filter.linkTargets) && filter.linkTargets.length ? String(filter.linkTargets[0]) : '';

        if (filter.isMultiple) {
            filter.value = Array.isArray(filter.value) ? filter.value : [];
            filter.valueTitle = Array.isArray(filter.valueTitle) ? filter.valueTitle : [];
        } else {
            filter.value = filter.value ? String(filter.value) : '';
            filter.valueTitle = filter.valueTitle ? String(filter.valueTitle) : '';
        }

        const tagSelector = new BX.UI.EntitySelector.TagSelector({
            multiple: !!filter.isMultiple,
            dialogOptions: {
                context: 'gnc-otchet-report-' + String(filter.key || filter.fieldCode || 'link'),
                entities: entities,
                enableSearch: true
            },
            events: {
                onTagAdd: function () {
                    syncTagValues();
                },
                onTagRemove: function () {
                    syncTagValues();
                }
            }
        });

        tagSelector.renderTo(selectorNode);

        const dialog = tagSelector.getDialog();
        if (dialog && typeof dialog.subscribe === 'function' && primaryEntityCode) {
            dialog.subscribe('onSearch', function (event) {
                const data = event && typeof event.getData === 'function' ? event.getData() : {};
                const q = String((data && (data.query || data.searchQuery || data.search)) || '').trim();
                if (!q) {
                    return;
                }

                request('searchEntityItems', {
                    entityCode: primaryEntityCode,
                    query: q,
                    limit: '20'
                }).then(function (resp) {
                    if (resp.status !== 'success' || !resp.data || !Array.isArray(resp.data.items)) {
                        return;
                    }

                    resp.data.items.forEach(function (item) {
                        const id = String(item.id || '');
                        if (!id) {
                            return;
                        }
                        if (dialog.getItem && dialog.getItem(id)) {
                            return;
                        }
                        dialog.addItem({
                            id: id,
                            entityId: 'crm',
                            title: String(item.title || ('#' + id))
                        });
                    });
                }).catch(function () {});
            });
        }

        function syncTagValues() {
            const tags = tagSelector.getTags();
            if (filter.isMultiple) {
                filter.value = tags.map(function (tag) { return String(tag.getId()); });
                filter.valueTitle = tags.map(function (tag) { return String(tag.getTitle()); });
                return;
            }
            const first = tags[0] || null;
            filter.value = first ? String(first.getId()) : '';
            filter.valueTitle = first ? String(first.getTitle()) : '';
        }

        if (filter.isMultiple && Array.isArray(filter.value) && filter.value.length) {
            filter.value.forEach(function (id, idx) {
                const title = Array.isArray(filter.valueTitle) ? String(filter.valueTitle[idx] || ('#' + id)) : ('#' + id);
                tagSelector.addTag({ id: String(id), entityId: 'crm', title: title });
            });
            syncTagValues();
        } else if (!filter.isMultiple && filter.value) {
            tagSelector.addTag({
                id: String(filter.value),
                entityId: 'crm',
                title: String(filter.valueTitle || ('#' + String(filter.value)))
            });
            syncTagValues();
        }

        return wrap;
    }

    function createTextFilterControl(filter) {
        const wrap = document.createElement('div');
        wrap.className = 'gnc-operator-filter';

        const op = document.createElement('select');
        op.className = 'gnc-col-filter gnc-op-select';
        addOption(op, 'contains', 'Содержит');
        addOption(op, 'equals', 'Равно');
        addOption(op, 'starts', 'Начинается с');
        addOption(op, 'ends', 'Заканчивается на');

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'gnc-col-filter';
        input.placeholder = 'Введите значение';

        filter.value = filter.value && typeof filter.value === 'object' ? filter.value : { op: 'contains', val: '' };
        op.value = filter.value.op || 'contains';
        input.value = filter.value.val || '';

        op.addEventListener('change', function () {
            filter.value.op = op.value;
        });
        input.addEventListener('input', function () {
            filter.value.val = input.value;
        });

        wrap.appendChild(op);
        wrap.appendChild(input);
        return wrap;
    }

    function createNumberFilterControl(filter) {
        const wrap = document.createElement('div');
        wrap.className = 'gnc-operator-filter';

        const op = document.createElement('select');
        op.className = 'gnc-col-filter gnc-op-select';
        addOption(op, 'eq', '=');
        addOption(op, 'gt', '>');
        addOption(op, 'lt', '<');
        addOption(op, 'gte', '>=');
        addOption(op, 'lte', '<=');

        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'gnc-col-filter';
        input.placeholder = 'Введите число';

        filter.value = filter.value && typeof filter.value === 'object' ? filter.value : { op: 'eq', val: '' };
        op.value = filter.value.op || 'eq';
        input.value = filter.value.val || '';

        op.addEventListener('change', function () {
            filter.value.op = op.value;
        });
        input.addEventListener('input', function () {
            filter.value.val = input.value;
        });

        wrap.appendChild(op);
        wrap.appendChild(input);
        return wrap;
    }

    function createFilterControl(filter) {
        if (filter.type === 'date') {
            return createDateFilterControl(filter);
        }
        if (filter.type === 'enum') {
            return createEnumFilterControl(filter);
        }
        if (filter.type === 'link') {
            return createLinkSearchControl(filter);
        }
        if (filter.type === 'boolean') {
            const select = document.createElement('select');
            select.className = 'gnc-col-filter';
            addOption(select, '', 'Любое значение');
            addOption(select, 'Y', 'Да');
            addOption(select, 'N', 'Нет');
            select.value = String(filter.value || '');
            select.addEventListener('change', function () {
                filter.value = select.value;
            });
            return select;
        }
        if (filter.type === 'number') {
            return createNumberFilterControl(filter);
        }

        return createTextFilterControl(filter);
    }

    function renderFilters(filters) {
        filtersWrap.innerHTML = '';

        if (!filters.length) {
            filtersWrap.innerHTML = '<div class="gnc-empty">В шаблоне не выбраны поля фильтров.</div>';
            return;
        }

        filters.forEach(function (filter) {
            const row = document.createElement('div');
            row.className = 'gnc-report-filter-row';

            const label = document.createElement('label');
            label.className = 'gnc-node-meta';
            label.textContent = filter.entityTitle + ': ' + filter.fieldTitle;

            row.appendChild(label);
            row.appendChild(createFilterControl(filter));
            filtersWrap.appendChild(row);
        });
    }

    function loadEntityMetaMap(config) {
        const nodes = Array.isArray(config.nodes) ? config.nodes : [];
        const entityCodes = [];
        nodes.forEach(function (node) {
            const code = String(node.entityCode || '');
            if (code && entityCodes.indexOf(code) < 0) {
                entityCodes.push(code);
            }
        });

        const result = {};
        if (!entityCodes.length) {
            return Promise.resolve(result);
        }

        return Promise.all(entityCodes.map(function (code) {
            return request('getEntityMeta', { entityCode: code }).then(function (resp) {
                if (resp.status === 'success' && resp.data) {
                    result[code] = resp.data;
                } else {
                    result[code] = { entity: { code: code, title: code }, fields: [] };
                }
            }).catch(function () {
                result[code] = { entity: { code: code, title: code }, fields: [] };
            });
        })).then(function () {
            return result;
        });
    }

    function init() {
        if (!cfg.templateId) {
            metaEl.textContent = 'Не передан ID шаблона.';
            return;
        }

        request('getTemplate', { id: cfg.templateId }).then(function (resp) {
            if (resp.status !== 'success' || !resp.data || !resp.data.item) {
                throw new Error('Шаблон не найден');
            }

            const tpl = resp.data.item;
            currentConfig = tpl.config || {};

            titleEl.textContent = 'Формирование отчета: ' + (tpl.name || 'Без названия');
            metaEl.textContent = 'Шаблон загружен. Настрой фильтры и нажми "Сформировать".';

            return loadEntityMetaMap(currentConfig).then(function (entityMetaMap) {
                const columns = collectColumns(currentConfig, entityMetaMap);
                const filters = collectFilterFields(currentConfig, entityMetaMap);
                currentConfig.runtimeFilters = filters;

                renderFilters(filters);
                renderColumns(columns);
            });
        }).catch(function (e) {
            metaEl.textContent = e && e.message ? e.message : 'Ошибка загрузки шаблона';
        });

        buildReportBtn.addEventListener('click', function () {
            if (window.top && window.top.BX && window.top.BX.UI && window.top.BX.UI.Notification && window.top.BX.UI.Notification.Center) {
                window.top.BX.UI.Notification.Center.notify({
                    content: 'Фильтры собраны. Следующий шаг — подключение расчета preview.',
                    autoHideDelay: 3000
                });
            } else {
                alert('Фильтры собраны. Следующий шаг — подключение расчета preview.');
            }
        });
    }

    init();
})();
