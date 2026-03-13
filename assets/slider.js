(function () {
    const cfg = window.GncOtchetSlider || {};
    const treeRoot = document.getElementById('entityTree');
    const rootEntitySelect = document.getElementById('rootEntitySelect');
    const contactFieldSelect = document.getElementById('contactFieldSelect');
    const saveBtn = document.getElementById('saveTemplateBtn');
    const templateNameInput = document.getElementById('templateNameInput');
    const formTitle = document.getElementById('sliderFormTitle');
    const permissionsCard = document.getElementById('permissionsCard');
    const permSubjectSelector = document.getElementById('permSubjectSelector');
    const permAssignedList = document.getElementById('permAssignedList');
    const permReadonlyHint = document.getElementById('permReadonlyHint');
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
        openRelationMenuNodeId: '',
        permissions: { users: {}, departments: {} },
        permissionSubjects: { users: {}, departments: {} },
        permissionsEditable: true,
        permissionSelectorReady: false,
        permissionTagSelector: null,
        permissionSelectorPromise: null,
        permissionSyncing: false
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
        renderPermissions();
        ensurePermissionTagSelector().then(function () {
            state.permissionSelectorReady = true;
            renderPermissions();
        });
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

            state.permissionsEditable = true;
            renderPermissions();
            clearTree();
            renderTree();
            renderColumns();
        }).catch(showError);
    }

    function loadTemplate(template) {
        formTitle.textContent = 'Редактирование шаблона';
        templateNameInput.value = template.name || '';

        const config = template.config || {};
        const currentUserId = Number(cfg.currentUserId || 0);
        const creatorId = Number(template.creatorId || 0);
        state.permissionsEditable = !!template.canManagePermissions || (!creatorId || !currentUserId || creatorId === currentUserId);
        state.permissions = normalizePermissions(config.permissions || {});
        state.permissionSubjects = subjectsFromTemplate(template);
        renderPermissions();
        ensurePermissionTagSelector().then(function () {
            state.permissionSelectorReady = true;
            renderPermissions();
        });

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

    function normalizePermissions(raw) {
        const src = raw && typeof raw === 'object' ? raw : {};
        const usersSrc = src.users && typeof src.users === 'object' ? src.users : {};
        const departmentsSrc = src.departments && typeof src.departments === 'object' ? src.departments : {};
        const out = { users: {}, departments: {} };

        Object.keys(usersSrc).forEach(function (id) {
            const role = normalizeRole(usersSrc[id]);
            if (role) {
                out.users[String(parseInt(id, 10))] = role;
            }
        });
        Object.keys(departmentsSrc).forEach(function (id) {
            const role = normalizeRole(departmentsSrc[id]);
            if (role) {
                out.departments[String(parseInt(id, 10))] = role;
            }
        });

        return out;
    }

    function normalizeRole(role) {
        const value = String(role || '').toLowerCase();
        if (value === 'view' || value === 'edit' || value === 'delete' || value === 'full') {
            return value;
        }
        return '';
    }

    function subjectsFromTemplate(template) {
        const out = { users: {}, departments: {} };
        const source = template && template.permissionSubjects && typeof template.permissionSubjects === 'object'
            ? template.permissionSubjects
            : {};

        const users = Array.isArray(source.users) ? source.users : [];
        users.forEach(function (item) {
            const id = String(parseInt(item && item.id, 10) || 0);
            if (!id || id === '0') {
                return;
            }
            out.users[id] = {
                id: Number(id),
                name: String((item && item.name) || ('#' + id)),
                url: String((item && item.url) || '/company/personal/user/' + id + '/')
            };
        });

        const departments = Array.isArray(source.departments) ? source.departments : [];
        departments.forEach(function (item) {
            const id = String(parseInt(item && item.id, 10) || 0);
            if (!id || id === '0') {
                return;
            }
            out.departments[id] = {
                id: Number(id),
                name: String((item && item.name) || ('Отдел #' + id))
            };
        });

        return out;
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

    function roleLabel(role) {
        if (role === 'delete') { return 'Удалять'; }
        if (role === 'edit') { return 'Изменять'; }
        return 'Пользоваться';
    }

    function makePermissionSubjectKey(kind, id) {
        return String(kind) + ':' + String(parseInt(id, 10) || 0);
    }

    function normalizeSubjectId(rawId) {
        if (typeof rawId === 'number' && Number.isFinite(rawId)) {
            return Math.max(0, parseInt(String(rawId), 10) || 0);
        }
        const value = String(rawId || '').trim();
        if (value === '') {
            return 0;
        }
        if (/^\d+$/.test(value)) {
            return parseInt(value, 10) || 0;
        }
        const matched = value.match(/(\d+)/);
        return matched ? (parseInt(matched[1], 10) || 0) : 0;
    }

    function parsePermissionSubjectFromTag(tag) {
        if (!tag) {
            return null;
        }
        const item = tag.getItem ? tag.getItem() : null;
        const entityId = String(
            (item && item.getEntityId ? item.getEntityId() : '') ||
            (tag.getEntityId ? tag.getEntityId() : '')
        ).toLowerCase();
        const id = normalizeSubjectId(
            (item && item.getId ? item.getId() : 0) ||
            (tag.getId ? tag.getId() : 0)
        );
        if (!id) {
            return null;
        }

        if (entityId === 'user' || entityId.indexOf('user') >= 0 || entityId.indexOf('employee') >= 0) {
            return { kind: 'users', id: id };
        }
        if (
            entityId === 'department' ||
            entityId === 'structure-node' ||
            entityId.indexOf('department') >= 0 ||
            entityId.indexOf('structure') >= 0
        ) {
            return { kind: 'departments', id: id };
        }
        return null;
    }

    function ensurePermissionTagSelector() {
        if (!permSubjectSelector || state.permissionTagSelector) {
            return Promise.resolve(state.permissionTagSelector);
        }
        if (state.permissionSelectorPromise) {
            return state.permissionSelectorPromise;
        }
        if (!window.BX || !BX.Runtime) {
            return Promise.resolve(null);
        }

        state.permissionSelectorPromise = BX.Runtime.loadExtension('ui.entity-selector').then(function () {
            if (!BX.UI || !BX.UI.EntitySelector || !BX.UI.EntitySelector.TagSelector) {
                return null;
            }
            if (state.permissionTagSelector) {
                return state.permissionTagSelector;
            }
            permSubjectSelector.innerHTML = '';
            state.permissionTagSelector = new BX.UI.EntitySelector.TagSelector({
                id: 'gnc-template-permission-selector',
                multiple: true,
                textBoxWidth: '100%',
                textBoxPlaceholder: 'Выберите сотрудников и отделы',
                dialogOptions: {
                    multiple: true,
                    dropdownMode: true,
                    enableSearch: true,
                    entities: [
                        { id: 'user', options: { intranetUsersOnly: true } },
                        { id: 'department', options: { selectMode: 'usersAndDepartments' } },
                        { id: 'structure-node', options: {} }
                    ]
                },
                events: {
                    onTagAdd: function (event) {
                        if (state.permissionSyncing) {
                            return;
                        }
                        if (!state.permissionsEditable) {
                            return;
                        }
                        const tag = event.getData().tag;
                        const subject = parsePermissionSubjectFromTag(tag);
                        if (!subject) {
                            return;
                        }

                        const idKey = String(subject.id);
                        const role = 'view';
                        state.permissions[subject.kind][idKey] = role;

                        const title = String(tag.getTitle ? tag.getTitle() : ('#' + idKey));
                        if (subject.kind === 'users') {
                            state.permissionSubjects.users[idKey] = {
                                id: Number(idKey),
                                name: title,
                                url: '/company/personal/user/' + idKey + '/'
                            };
                        } else {
                            state.permissionSubjects.departments[idKey] = {
                                id: Number(idKey),
                                name: title
                            };
                        }
                        renderPermissions();
                    },
                    onTagRemove: function (event) {
                        if (state.permissionSyncing) {
                            return;
                        }
                        if (!state.permissionsEditable) {
                            return;
                        }
                        const tag = event.getData().tag;
                        const subject = parsePermissionSubjectFromTag(tag);
                        if (!subject) {
                            return;
                        }
                        const idKey = String(subject.id);
                        delete state.permissions[subject.kind][idKey];
                        renderPermissions();
                    }
                }
            });
            state.permissionTagSelector.renderTo(permSubjectSelector);
            return state.permissionTagSelector;
        }).catch(function () {
            if (permSubjectSelector) {
                permSubjectSelector.innerHTML = '<div class="gnc-empty">Не удалось загрузить селектор сотрудников/отделов.</div>';
            }
            return null;
        }).finally(function () {
            state.permissionSelectorPromise = null;
        });
        return state.permissionSelectorPromise;
    }

    function syncTagsWithPermissions() {
        if (!state.permissionTagSelector) {
            return;
        }
        const selector = state.permissionTagSelector;
        const desired = {};

        Object.keys(state.permissions.users || {}).forEach(function (id) {
            if (!normalizeRole(state.permissions.users[id])) {
                return;
            }
            const key = makePermissionSubjectKey('users', id);
            desired[key] = {
                id: Number(id),
                entityId: 'user',
                title: String(((state.permissionSubjects.users || {})[id] || {}).name || ('#' + id))
            };
        });
        Object.keys(state.permissions.departments || {}).forEach(function (id) {
            if (!normalizeRole(state.permissions.departments[id])) {
                return;
            }
            const key = makePermissionSubjectKey('departments', id);
            desired[key] = {
                id: Number(id),
                entityId: 'department',
                title: String(((state.permissionSubjects.departments || {})[id] || {}).name || ('Отдел #' + id))
            };
        });

        state.permissionSyncing = true;
        try {
            const tags = selector.getTags();
            tags.forEach(function (tag) {
                const subject = parsePermissionSubjectFromTag(tag);
                if (!subject) {
                    return;
                }
                const key = makePermissionSubjectKey(subject.kind, subject.id);
                if (!desired[key]) {
                    selector.removeTag(tag);
                }
            });

            Object.keys(desired).forEach(function (key) {
                const item = desired[key];
                const exists = selector.getTags().some(function (tag) {
                    const subject = parsePermissionSubjectFromTag(tag);
                    if (!subject) {
                        return false;
                    }
                    return makePermissionSubjectKey(subject.kind, subject.id) === key;
                });
                if (!exists) {
                    selector.addTag({
                        id: item.id,
                        entityId: item.entityId,
                        title: item.title
                    });
                }
            });
        } finally {
            state.permissionSyncing = false;
        }
    }

    function renderPermissions() {
        const editable = !!state.permissionsEditable;
        if (permissionsCard) {
            if (state.templateId && !editable) {
                permissionsCard.style.display = 'none';
                return;
            }
            permissionsCard.style.display = '';
        }
        if (permReadonlyHint) {
            permReadonlyHint.style.display = editable ? 'none' : '';
        }
        if (state.permissionTagSelector && typeof state.permissionTagSelector.setReadonly === 'function') {
            state.permissionTagSelector.setReadonly(!editable);
        }

        if (permAssignedList) {
            permAssignedList.innerHTML = '';
            const rows = [];
            Object.keys(state.permissions.users || {}).forEach(function (id) {
                const role = normalizeRole(state.permissions.users[id]);
                if (!role) {
                    return;
                }
                const subject = ((state.permissionSubjects || {}).users || {})[id] || {};
                rows.push({
                    kind: 'users',
                    id: id,
                    role: role,
                    name: String(subject.name || ('#' + id)),
                    url: String(subject.url || '/company/personal/user/' + id + '/')
                });
            });
            Object.keys(state.permissions.departments || {}).forEach(function (id) {
                const role = normalizeRole(state.permissions.departments[id]);
                if (!role) {
                    return;
                }
                const subject = ((state.permissionSubjects || {}).departments || {})[id] || {};
                rows.push({
                    kind: 'departments',
                    id: id,
                    role: role,
                    name: String(subject.name || ('Отдел #' + id)),
                    url: ''
                });
            });

            if (!rows.length) {
                permAssignedList.innerHTML = '<div class="gnc-empty">Права не назначены</div>';
            } else {
                rows.forEach(function (rowData) {
                    const row = document.createElement('div');
                    row.className = 'gnc-perm-item';

                    const name = document.createElement('div');
                    name.className = 'gnc-perm-item-name';
                    if (rowData.kind === 'users') {
                        const link = document.createElement('a');
                        link.href = rowData.url;
                        link.target = '_blank';
                        link.textContent = rowData.name;
                        name.appendChild(link);
                    } else {
                        name.textContent = rowData.name;
                    }
                    row.appendChild(name);

                    const roleSelect = document.createElement('select');
                    roleSelect.className = 'gnc-perm-item-role';
                    roleSelect.disabled = !editable;
                    [
                        { id: 'view', title: 'Пользоваться' },
                        { id: 'edit', title: 'Изменять' },
                        { id: 'delete', title: 'Удалять' },
                        { id: 'full', title: 'Полный доступ' }
                    ].forEach(function (opt) {
                        const option = document.createElement('option');
                        option.value = opt.id;
                        option.textContent = opt.title;
                        option.selected = rowData.role === opt.id;
                        roleSelect.appendChild(option);
                    });
                    roleSelect.addEventListener('change', function () {
                        if (!state.permissionsEditable) {
                            return;
                        }
                        const role = normalizeRole(roleSelect.value) || 'view';
                        state.permissions[rowData.kind][rowData.id] = role;
                        renderPermissions();
                    });
                    row.appendChild(roleSelect);

                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'gnc-perm-item-remove';
                    removeBtn.textContent = '×';
                    removeBtn.disabled = !editable;
                    removeBtn.title = 'Удалить право';
                    removeBtn.addEventListener('click', function () {
                        if (!state.permissionsEditable) {
                            return;
                        }
                        delete state.permissions[rowData.kind][rowData.id];
                        renderPermissions();
                    });
                    row.appendChild(removeBtn);

                    permAssignedList.appendChild(row);
                });
            }
        }

        syncTagsWithPermissions();
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
            nodes: nodes,
            permissions: {
                users: Object.assign({}, state.permissions.users),
                departments: Object.assign({}, state.permissions.departments)
            }
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

    init();
})();
