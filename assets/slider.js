(function () {
    const cfg = window.GncOtchetSlider || {};
    const treeRoot = document.getElementById('entityTree');
    const rootEntitySelect = document.getElementById('rootEntitySelect');
    const contactFieldSelect = document.getElementById('contactFieldSelect');
    const saveBtn = document.getElementById('saveTemplateBtn');
    const openReportBtn = document.getElementById('openReportBtn');
    const templateNameInput = document.getElementById('templateNameInput');
    const formTitle = document.getElementById('sliderFormTitle');
    const relationMenuOverlayId = 'gncRelationMenuOverlay';

    const state = {
        rootEntities: [],
        rootEntityCode: '',
        nodes: {},
        rootNodeId: '',
        templateId: cfg.templateId || '',
        metaCache: {},
        columnOrder: [],
        contactFieldCode: '',
        selectedRelations: {},
        expandedNodes: {},
        fieldSearch: {},
        pendingSearchFocus: null,
        openRelationMenuNodeId: ''
    };

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

    function fetchMeta(entityCode) {
        if (state.metaCache[entityCode]) {
            return Promise.resolve(state.metaCache[entityCode]);
        }

        return request('getEntityMeta', { entityCode: entityCode }).then(function (resp) {
            if (resp.status !== 'success') {
                throw new Error('Ошибка загрузки полей сущности');
            }
            state.metaCache[entityCode] = resp.data;
            return resp.data;
        });
    }

    function init() {
        Promise.all([
            request('getRootEntities', {}),
            state.templateId ? request('getTemplate', { id: state.templateId }) : Promise.resolve({ status: 'success', data: { item: null } })
        ]).then(function (res) {
            const entitiesResp = res[0];
            const templateResp = res[1];

            if (entitiesResp.status !== 'success') {
                throw new Error('Не удалось загрузить список сущностей');
            }

            state.rootEntities = entitiesResp.data.items || [];
            renderRootSelect('');

            if (templateResp.status === 'success' && templateResp.data.item) {
                loadTemplate(templateResp.data.item);
                return;
            }

            clearTree();
            renderTree();
            renderColumns();
        }).catch(showError);
    }

    function loadTemplate(template) {
        formTitle.textContent = 'Редактирование шаблона';
        templateNameInput.value = template.name || '';

        const config = template.config || {};
        state.contactFieldCode = String(config.contactFieldCode || template.contactFieldCode || '');
        if (config.mode === 'tree' && config.rootEntity && Array.isArray(config.nodes) && config.nodes.length) {
            restoreTreeConfig(config);
            return;
        }

        clearTree();
        renderTree();
        renderColumns();
    }

    function restoreTreeConfig(config) {
        clearTree();
        state.rootEntityCode = config.rootEntity;
        state.columnOrder = Array.isArray(config.columnOrder) ? config.columnOrder.map(String) : [];
        state.contactFieldCode = String(config.contactFieldCode || state.contactFieldCode || '');
        renderRootSelect(state.rootEntityCode);

        (config.nodes || []).forEach(function (entry) {
            const id = String(entry.id || '');
            if (!id) {
                return;
            }
            state.nodes[id] = {
                id: id,
                parentId: entry.parentId ? String(entry.parentId) : null,
                parentFieldCode: entry.parentFieldCode ? String(entry.parentFieldCode) : '',
                parentFieldTitle: entry.parentFieldTitle ? String(entry.parentFieldTitle) : '',
                entityCode: String(entry.entityCode || ''),
                entityTitle: String(entry.entityTitle || ''),
                fields: [],
                selectedFields: Array.isArray(entry.selectedFields) ? entry.selectedFields.map(String) : [],
                childrenIds: []
            };
        });

        Object.keys(state.nodes).forEach(function (id) {
            const node = state.nodes[id];
            if (!node.parentId) {
                state.rootNodeId = id;
                return;
            }
            const parent = state.nodes[node.parentId];
            if (parent) {
                parent.childrenIds.push(id);
            }
        });

        rebuildSelectedRelationsFromTree();

        const tasks = Object.keys(state.nodes).map(function (id) {
            const node = state.nodes[id];
            return fetchMeta(node.entityCode).then(function (meta) {
                node.fields = meta.fields || [];
                node.entityTitle = meta.entity.title || node.entityCode;
            });
        });

        Promise.all(tasks).then(function () {
            refreshRootSpecialFieldSelectors();
            renderTree();
            renderColumns();
        }).catch(showError);
    }

    function clearTree() {
        state.rootEntityCode = '';
        state.nodes = {};
        state.rootNodeId = '';
        state.columnOrder = [];
        state.contactFieldCode = '';
        state.selectedRelations = {};
        state.expandedNodes = {};
        state.fieldSearch = {};
        state.pendingSearchFocus = null;
        state.openRelationMenuNodeId = '';
        renderRootSpecialFieldSelectors([]);
    }

    function renderRootSelect(selectedCode) {
        rootEntitySelect.innerHTML = '<option value="">Выберите сущность...</option>';
        state.rootEntities.forEach(function (entity) {
            const option = document.createElement('option');
            option.value = entity.code;
            option.textContent = entity.title;
            option.selected = selectedCode === entity.code;
            rootEntitySelect.appendChild(option);
        });
    }

    function renderRootSpecialFieldSelectors(contactItems) {
        if (contactFieldSelect) {
            contactFieldSelect.innerHTML = '<option value="">Не выбрано</option>';
            contactItems.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.code;
                option.textContent = item.title;
                option.selected = state.contactFieldCode === item.code;
                contactFieldSelect.appendChild(option);
            });
        }
    }

    function refreshRootSpecialFieldSelectors() {
        const rootNode = state.rootNodeId ? state.nodes[state.rootNodeId] : null;
        const fields = rootNode && Array.isArray(rootNode.fields) ? rootNode.fields : [];

        const contactItems = fields.filter(function (field) {
            if (!field || !field.isLink) {
                return false;
            }
            const targets = Array.isArray(field.linkTargets) ? field.linkTargets.map(function (target) {
                return String(target).toUpperCase();
            }) : [];
            return targets.indexOf('CONTACT') >= 0;
        }).map(function (field) {
            return { code: String(field.code || ''), title: String(field.title || field.code || '') };
        }).filter(function (field) {
            return field.code !== '';
        });

        if (state.contactFieldCode && contactItems.every(function (item) { return item.code !== state.contactFieldCode; })) {
            state.contactFieldCode = '';
        }

        renderRootSpecialFieldSelectors(contactItems);
    }

    function handleRootChange(entityCode) {
        clearTree();
        state.rootEntityCode = entityCode;

        if (!entityCode) {
            refreshRootSpecialFieldSelectors();
            renderTree();
            renderColumns();
            return;
        }

        const rootNodeId = 'root:' + entityCode;
        state.rootNodeId = rootNodeId;
        state.nodes[rootNodeId] = {
            id: rootNodeId,
            parentId: null,
            parentFieldCode: '',
            parentFieldTitle: '',
            entityCode: entityCode,
            entityTitle: entityCode,
            fields: [],
            selectedFields: [],
            childrenIds: []
        };

        fetchMeta(entityCode).then(function (meta) {
            const node = state.nodes[rootNodeId];
            if (!node) {
                return;
            }
            node.fields = meta.fields || [];
            node.entityTitle = meta.entity.title || entityCode;
            refreshRootSpecialFieldSelectors();
            renderTree();
            renderColumns();
        }).catch(showError);
    }

    function onFieldToggle(nodeId, fieldCode, checked) {
        const node = state.nodes[nodeId];
        if (!node) {
            return;
        }

        const idx = node.selectedFields.indexOf(fieldCode);
        if (checked && idx < 0) {
            node.selectedFields.push(fieldCode);
        }
        if (!checked && idx >= 0) {
            node.selectedFields.splice(idx, 1);
        }

        renderTree();
        renderColumns();
    }

    function isNodeExpanded(nodeId) {
        return !!state.expandedNodes[nodeId];
    }

    function toggleNodeExpanded(nodeId) {
        const willExpand = !isNodeExpanded(nodeId);
        if (!willExpand) {
            delete state.expandedNodes[nodeId];
        } else {
            state.expandedNodes[nodeId] = true;
        }

        const fieldsBox = treeRoot.querySelector('.gnc-fields-box[data-node-id="' + String(nodeId).replace(/"/g, '\\"') + '"]');
        const animWrap = fieldsBox ? fieldsBox.querySelector('.gnc-fields-anim') : null;

        if (!fieldsBox || !animWrap) {
            renderTree();
            return;
        }

        if (willExpand) {
            fieldsBox.classList.remove('is-collapsed');
            animWrap.style.maxHeight = '0px';
            animWrap.style.opacity = '0';
            requestAnimationFrame(function () {
                const targetHeight = animWrap.scrollHeight;
                animWrap.style.maxHeight = targetHeight + 'px';
                animWrap.style.opacity = '1';
            });
            animWrap.addEventListener('transitionend', function onEnd(event) {
                if (event.propertyName !== 'max-height') {
                    return;
                }
                animWrap.style.maxHeight = 'none';
                animWrap.removeEventListener('transitionend', onEnd);
            });
        } else {
            const fromHeight = animWrap.scrollHeight;
            animWrap.style.maxHeight = fromHeight + 'px';
            animWrap.style.opacity = '1';
            animWrap.offsetHeight;
            fieldsBox.classList.add('is-collapsed');
            animWrap.style.maxHeight = '0px';
            animWrap.style.opacity = '0';
        }
    }

    function getFieldSearch(nodeId) {
        return String(state.fieldSearch[nodeId] || '');
    }

    function setFieldSearch(nodeId, query) {
        const value = String(query || '');
        if (value === '') {
            delete state.fieldSearch[nodeId];
        } else {
            state.fieldSearch[nodeId] = value;
        }
        state.pendingSearchFocus = null;
        renderTree();
    }

    function setFieldSearchAndKeepFocus(nodeId, query, start, end) {
        const value = String(query || '');
        if (value === '') {
            delete state.fieldSearch[nodeId];
        } else {
            state.fieldSearch[nodeId] = value;
        }
        state.pendingSearchFocus = {
            nodeId: String(nodeId || ''),
            start: Number.isInteger(start) ? start : value.length,
            end: Number.isInteger(end) ? end : value.length
        };
        renderTree();
    }

    function restorePendingSearchFocus() {
        const pending = state.pendingSearchFocus;
        state.pendingSearchFocus = null;
        if (!pending || !pending.nodeId) {
            return;
        }

        const selector = '.gnc-field-search-input[data-node-id="' + pending.nodeId.replace(/"/g, '\\"') + '"]';
        const input = treeRoot.querySelector(selector);
        if (!input) {
            return;
        }

        input.focus({ preventScroll: true });
        const length = input.value.length;
        const start = Math.max(0, Math.min(length, pending.start));
        const finish = Math.max(0, Math.min(length, pending.end));
        try {
            input.setSelectionRange(start, finish);
        } catch (e) {}
    }

    function toggleRelationMenu(nodeId) {
        state.openRelationMenuNodeId = state.openRelationMenuNodeId === nodeId ? '' : nodeId;
        renderTree();
    }

    function makeRelationKey(fieldCode, targetCode) {
        return String(fieldCode) + '::' + String(targetCode);
    }

    function parseRelationKey(value) {
        const parts = String(value || '').split('::');
        if (parts.length < 2) {
            return { fieldCode: '', targetCode: '' };
        }
        return {
            fieldCode: String(parts[0] || ''),
            targetCode: String(parts[1] || '')
        };
    }

    function buildRelationOptions(node) {
        const options = [];
        const seen = {};
        (node.fields || []).forEach(function (field) {
            if (!field || !field.isLink) {
                return;
            }
            const fieldCode = String(field.code || '');
            const fieldTitle = String(field.title || fieldCode);
            const targets = Array.isArray(field.linkTargets) ? field.linkTargets : [];
            targets.forEach(function (targetCode) {
                targetCode = String(targetCode || '');
                if (!targetCode) {
                    return;
                }
                const key = makeRelationKey(fieldCode, targetCode);
                if (seen[key]) {
                    return;
                }
                seen[key] = true;
                options.push({
                    key: key,
                    fieldCode: fieldCode,
                    fieldTitle: fieldTitle,
                    targetCode: targetCode
                });
            });
        });

        return options;
    }

    function rebuildSelectedRelationsFromTree() {
        state.selectedRelations = {};
        Object.keys(state.nodes).forEach(function (nodeId) {
            const node = state.nodes[nodeId];
            if (!node || !Array.isArray(node.childrenIds) || !node.childrenIds.length) {
                return;
            }

            const selected = [];
            node.childrenIds.forEach(function (childId) {
                const child = state.nodes[childId];
                if (!child) {
                    return;
                }
                selected.push(makeRelationKey(child.parentFieldCode, child.entityCode));
            });
            state.selectedRelations[nodeId] = selected;
        });
    }

    function onRelationChange(nodeId, relationKeys) {
        const node = state.nodes[nodeId];
        if (!node) {
            return;
        }

        const selectedSet = {};
        (relationKeys || []).forEach(function (key) {
            key = String(key || '');
            if (key !== '') {
                selectedSet[key] = true;
            }
        });

        const existingByRelation = {};
        (node.childrenIds || []).forEach(function (childId) {
            const child = state.nodes[childId];
            if (!child) {
                return;
            }
            existingByRelation[makeRelationKey(child.parentFieldCode, child.entityCode)] = childId;
        });

        Object.keys(existingByRelation).forEach(function (relationKey) {
            if (!selectedSet[relationKey]) {
                removeNodeRecursive(existingByRelation[relationKey]);
            }
        });

        const addPromises = [];
        Object.keys(selectedSet).forEach(function (relationKey) {
            if (existingByRelation[relationKey]) {
                return;
            }

            const parsed = parseRelationKey(relationKey);
            if (!parsed.fieldCode || !parsed.targetCode) {
                return;
            }

            const field = (node.fields || []).find(function (item) {
                return String(item.code) === parsed.fieldCode;
            });
            if (!field) {
                return;
            }

            const childId = node.id + '::' + parsed.fieldCode + '::' + parsed.targetCode;
            const childNode = {
                id: childId,
                parentId: node.id,
                parentFieldCode: parsed.fieldCode,
                parentFieldTitle: String(field.title || parsed.fieldCode),
                entityCode: parsed.targetCode,
                entityTitle: parsed.targetCode,
                fields: [],
                selectedFields: [],
                childrenIds: []
            };
            state.nodes[childId] = childNode;
            node.childrenIds.push(childId);

            addPromises.push(
                fetchMeta(parsed.targetCode).then(function (meta) {
                    const child = state.nodes[childId];
                    if (!child) {
                        return;
                    }
                    child.fields = meta.fields || [];
                    child.entityTitle = meta.entity.title || parsed.targetCode;
                })
            );
        });

        node.childrenIds = (node.childrenIds || []).filter(function (id) { return !!state.nodes[id]; });
        state.selectedRelations[nodeId] = Object.keys(selectedSet);

        Promise.all(addPromises).then(function () {
            renderTree();
            renderColumns();
        }).catch(showError);
    }

    function removeNodeRecursive(nodeId) {
        const node = state.nodes[nodeId];
        if (!node) {
            return;
        }

        (node.childrenIds || []).forEach(function (childId) {
            removeNodeRecursive(childId);
        });

        if (node.parentId && state.nodes[node.parentId]) {
            const parent = state.nodes[node.parentId];
            parent.childrenIds = parent.childrenIds.filter(function (id) { return id !== nodeId; });
        }

        delete state.nodes[nodeId];
        delete state.selectedRelations[nodeId];
        delete state.expandedNodes[nodeId];
        if (state.openRelationMenuNodeId === nodeId) {
            state.openRelationMenuNodeId = '';
        }
    }

    function renderTree() {
        treeRoot.innerHTML = '';

        if (!state.rootNodeId || !state.nodes[state.rootNodeId]) {
            treeRoot.innerHTML = '<div class="gnc-empty">Выберите основную сущность для загрузки полей.</div>';
            removeRelationMenuOverlay();
            return;
        }

        treeRoot.appendChild(renderNodeBranch(state.rootNodeId, 1));
        renderRelationMenuOverlay();
        restorePendingSearchFocus();
    }

    function renderNodeBranch(nodeId, depth) {
        const node = state.nodes[nodeId];
        const branch = document.createElement('div');
        branch.className = 'gnc-branch-row';

        branch.appendChild(renderNodeColumn(nodeId, depth));

        const children = (node.childrenIds || []).filter(function (id) { return !!state.nodes[id]; });
        if (children.length) {
            const childrenWrap = document.createElement('div');
            childrenWrap.className = 'gnc-branch-children';
            children.forEach(function (childId) {
                childrenWrap.appendChild(renderNodeBranch(childId, depth + 1));
            });
            branch.appendChild(childrenWrap);
        }

        return branch;
    }

    function renderNodeColumn(nodeId, depth) {
        const node = state.nodes[nodeId];
        const block = document.createElement('div');
        block.className = 'gnc-level gnc-level-column';

        const titleRow = document.createElement('div');
        titleRow.className = 'gnc-node-title-row';

        const title = document.createElement('div');
        title.className = 'gnc-node-title';
        title.textContent = depth === 1 ? node.entityTitle : ('Связанная сущность: ' + node.entityTitle);
        titleRow.appendChild(title);

        const titleActions = document.createElement('div');
        titleActions.className = 'gnc-node-title-actions';

        const relationOptions = buildRelationOptions(node);
        if (relationOptions.length) {
            const relationBtn = document.createElement('button');
            relationBtn.type = 'button';
            relationBtn.className = 'gnc-relation-trigger' + (state.openRelationMenuNodeId === node.id ? ' is-open' : '');
            relationBtn.title = 'Связанные сущности';
            relationBtn.textContent = '🔗';
            relationBtn.dataset.nodeId = node.id;
            relationBtn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggleRelationMenu(node.id);
            });
            titleActions.appendChild(relationBtn);
        }

        const collapseBtn = document.createElement('button');
        collapseBtn.type = 'button';
        collapseBtn.className = 'gnc-collapse-toggle';
        collapseBtn.title = isNodeExpanded(node.id) ? 'Свернуть поля' : 'Развернуть поля';
        collapseBtn.textContent = isNodeExpanded(node.id) ? '▾' : '▸';
        collapseBtn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            toggleNodeExpanded(node.id);
        });
        titleActions.appendChild(collapseBtn);
        titleRow.appendChild(titleActions);
        block.appendChild(titleRow);

        if (depth > 1) {
            const meta = document.createElement('div');
            meta.className = 'gnc-node-meta';
            meta.textContent = 'Через поле: ' + (node.parentFieldTitle || node.parentFieldCode);
            block.appendChild(meta);
        }

        const fieldsBox = document.createElement('div');
        fieldsBox.className = 'gnc-fields-box' + (isNodeExpanded(node.id) ? '' : ' is-collapsed');
        fieldsBox.dataset.nodeId = node.id;

        if (!node.fields.length) {
            fieldsBox.innerHTML = '<div class="gnc-empty">Загрузка полей...</div>';
        } else {
            const collapsedInfo = document.createElement('div');
            collapsedInfo.className = 'gnc-collapsed-hint';
            collapsedInfo.textContent = 'Поля свернуты';
            fieldsBox.appendChild(collapsedInfo);

            const animWrap = document.createElement('div');
            animWrap.className = 'gnc-fields-anim';
            animWrap.style.maxHeight = isNodeExpanded(node.id) ? 'none' : '0px';
            animWrap.style.opacity = isNodeExpanded(node.id) ? '1' : '0';

            const searchWrap = document.createElement('div');
            searchWrap.className = 'gnc-field-search';

            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'gnc-field-search-input';
            searchInput.dataset.nodeId = node.id;
            searchInput.placeholder = 'Поиск поля...';
            searchInput.value = getFieldSearch(node.id);
            searchInput.addEventListener('click', function (event) {
                event.stopPropagation();
            });
            searchInput.addEventListener('input', function () {
                setFieldSearchAndKeepFocus(
                    node.id,
                    searchInput.value,
                    searchInput.selectionStart,
                    searchInput.selectionEnd
                );
            });
            searchWrap.appendChild(searchInput);

            const clearSearchBtn = document.createElement('button');
            clearSearchBtn.type = 'button';
            clearSearchBtn.className = 'gnc-field-search-clear';
            clearSearchBtn.title = 'Сбросить поиск';
            clearSearchBtn.textContent = '×';
            clearSearchBtn.disabled = searchInput.value === '';
            clearSearchBtn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                setFieldSearchAndKeepFocus(node.id, '', 0, 0);
            });
            searchWrap.appendChild(clearSearchBtn);
            animWrap.appendChild(searchWrap);

            const grid = document.createElement('div');
            grid.className = 'gnc-field-grid';
            const searchValue = getFieldSearch(node.id).trim().toLowerCase();
            const visibleFields = searchValue === ''
                ? node.fields
                : node.fields.filter(function (field) {
                    const title = String(field.title || '').toLowerCase();
                    const code = String(field.code || '').toLowerCase();
                    return title.indexOf(searchValue) >= 0 || code.indexOf(searchValue) >= 0;
                });

            visibleFields.forEach(function (field) {
                const item = document.createElement('div');
                item.className = 'gnc-field-item';

                const label = document.createElement('label');
                label.className = 'gnc-field-check';

                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.checked = node.selectedFields.indexOf(field.code) >= 0;
                checkbox.addEventListener('change', function () {
                    onFieldToggle(node.id, field.code, checkbox.checked);
                });

                const text = document.createElement('span');
                text.textContent = field.typeTitle ? (field.title + ' [' + field.typeTitle + ']') : field.title;

                label.appendChild(checkbox);
                label.appendChild(text);
                item.appendChild(label);
                grid.appendChild(item);
            });

            if (!visibleFields.length) {
                const empty = document.createElement('div');
                empty.className = 'gnc-empty';
                empty.textContent = 'Поля не найдены';
                grid.appendChild(empty);
            }

            animWrap.appendChild(grid);
            fieldsBox.appendChild(animWrap);
        }

        block.appendChild(fieldsBox);

        const selected = Array.isArray(state.selectedRelations[node.id]) ? state.selectedRelations[node.id] : [];
        const selectedSet = {};
        selected.forEach(function (key) { selectedSet[key] = true; });
        const normalizedSelected = selected.filter(function (key) {
            return relationOptions.some(function (option) { return option.key === key; });
        });
        if (normalizedSelected.length !== selected.length) {
            state.selectedRelations[node.id] = normalizedSelected;
        }

        return block;
    }

    function removeRelationMenuOverlay() {
        const existing = document.getElementById(relationMenuOverlayId);
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }
    }

    function renderRelationMenuOverlay() {
        removeRelationMenuOverlay();
        if (!state.openRelationMenuNodeId) {
            return;
        }

        const node = state.nodes[state.openRelationMenuNodeId];
        if (!node) {
            state.openRelationMenuNodeId = '';
            return;
        }

        const relationOptions = buildRelationOptions(node);
        if (!relationOptions.length) {
            state.openRelationMenuNodeId = '';
            return;
        }

        const trigger = Array.prototype.find.call(
            treeRoot.querySelectorAll('.gnc-relation-trigger'),
            function (el) { return String(el.dataset.nodeId || '') === node.id; }
        );
        if (!trigger) {
            state.openRelationMenuNodeId = '';
            return;
        }

        const selected = Array.isArray(state.selectedRelations[node.id]) ? state.selectedRelations[node.id] : [];
        const selectedSet = {};
        selected.forEach(function (key) { selectedSet[key] = true; });

        const menu = document.createElement('div');
        menu.id = relationMenuOverlayId;
        menu.className = 'gnc-relation-menu gnc-relation-menu-overlay';
        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        relationOptions.forEach(function (option) {
            const selectedNow = !!selectedSet[option.key];
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'gnc-relation-menu-item';

            const marker = document.createElement('span');
            marker.className = 'gnc-relation-check';
            marker.textContent = selectedNow ? '✓' : '';
            item.appendChild(marker);

            const text = document.createElement('span');
            text.className = 'gnc-relation-text';
            text.textContent = option.fieldTitle + ' -> ' + option.targetCode;
            item.appendChild(text);

            item.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                const currentSelected = Array.isArray(state.selectedRelations[node.id]) ? state.selectedRelations[node.id].slice() : [];
                const idx = currentSelected.indexOf(option.key);
                if (idx >= 0) {
                    currentSelected.splice(idx, 1);
                } else {
                    currentSelected.push(option.key);
                }
                onRelationChange(node.id, currentSelected);
            });
            menu.appendChild(item);
        });

        document.body.appendChild(menu);

        const rect = trigger.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 1200;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 800;

        let left = rect.left;
        let top = rect.bottom + 6;

        if (left + menuRect.width > viewportWidth - 8) {
            left = Math.max(8, viewportWidth - menuRect.width - 8);
        }
        if (top + menuRect.height > viewportHeight - 8) {
            top = Math.max(8, rect.top - menuRect.height - 6);
        }

        menu.style.left = Math.round(left) + 'px';
        menu.style.top = Math.round(top) + 'px';
    }

    function getSelectedColumnRows() {
        const rows = [];

        function collect(nodeId, depth) {
            const node = state.nodes[nodeId];
            if (!node) {
                return;
            }

            node.selectedFields.forEach(function (fieldCode) {
                const field = node.fields.find(function (f) { return f.code === fieldCode; });
                if (!field) {
                    return;
                }

                rows.push({
                    key: node.id + '::' + field.code,
                    level: depth,
                    entityTitle: node.entityTitle,
                    fieldTitle: field.title
                });
            });

            node.childrenIds.forEach(function (childId) {
                collect(childId, depth + 1);
            });
        }

        if (state.rootNodeId) {
            collect(state.rootNodeId, 1);
        }

        return rows;
    }

    function syncColumnOrder(rows) {
        const keySet = {};
        rows.forEach(function (row) {
            keySet[row.key] = true;
        });

        state.columnOrder = state.columnOrder.filter(function (key) {
            return !!keySet[key];
        });

        rows.forEach(function (row) {
            if (state.columnOrder.indexOf(row.key) < 0) {
                state.columnOrder.push(row.key);
            }
        });
    }

    function renderColumns() {
        const rows = getSelectedColumnRows();
        syncColumnOrder(rows);
        return rows.length;
    }

    function collectConfig() {
        const nodes = Object.keys(state.nodes).map(function (id) {
            const node = state.nodes[id];
            return {
                id: node.id,
                parentId: node.parentId,
                parentFieldCode: node.parentFieldCode,
                parentFieldTitle: node.parentFieldTitle,
                entityCode: node.entityCode,
                entityTitle: node.entityTitle,
                selectedFields: node.selectedFields.slice()
            };
        });

        return {
            mode: 'tree',
            rootEntity: state.rootEntityCode,
            contactFieldCode: state.contactFieldCode,
            columnOrder: state.columnOrder.slice(),
            nodes: nodes
        };
    }

    function saveTemplate() {
        const name = templateNameInput.value.trim();
        if (!name) {
            alert('Укажите название шаблона');
            return;
        }
        if (!state.rootEntityCode) {
            alert('Выберите основную сущность');
            return;
        }

        const config = collectConfig();

        request('saveTemplate', {
            id: state.templateId,
            name: name,
            config: JSON.stringify(config)
        }).then(function (resp) {
            if (resp.status !== 'success') {
                throw new Error('Ошибка сохранения шаблона');
            }
            state.templateId = resp.data.item.id;
            if (window.top && window.top.BX && window.top.BX.UI && window.top.BX.UI.Notification && window.top.BX.UI.Notification.Center) {
                window.top.BX.UI.Notification.Center.notify({
                    content: 'Шаблон сохранен',
                    autoHideDelay: 3000
                });
            } else {
                alert('Шаблон сохранен');
            }

            window.location.href = String(cfg.listUrl || '/local/otchet/index.php');
        }).catch(showError);
    }

    function showError(error) {
        const message = error && error.message ? error.message : 'Ошибка';
        alert(message);
    }

    rootEntitySelect.addEventListener('change', function () {
        handleRootChange(rootEntitySelect.value);
    });
    if (contactFieldSelect) {
        contactFieldSelect.addEventListener('change', function () {
            state.contactFieldCode = String(contactFieldSelect.value || '');
        });
    }

    document.addEventListener('click', function () {
        if (state.openRelationMenuNodeId !== '') {
            state.openRelationMenuNodeId = '';
            removeRelationMenuOverlay();
            renderTree();
        }
    });
    window.addEventListener('resize', function () {
        if (state.openRelationMenuNodeId !== '') {
            renderRelationMenuOverlay();
        }
    });
    if (saveBtn) {
        saveBtn.addEventListener('click', saveTemplate);
    }
    if (openReportBtn) {
        openReportBtn.addEventListener('click', function () {
            if (!state.templateId) {
                alert('Сначала сохраните шаблон');
                return;
            }
            window.location.href = '/local/otchet/report.php?id=' + encodeURIComponent(String(state.templateId));
        });
    }

    init();
})();
