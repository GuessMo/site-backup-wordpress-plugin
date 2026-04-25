document.addEventListener('DOMContentLoaded', function () {
    // Tab-Switching
    document.querySelectorAll('.sb-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.sb-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.sb-tab-content').forEach(c => c.style.display = 'none');
            tab.classList.add('active');
            document.getElementById('sb-tab-' + tab.dataset.tab).style.display = 'block';
        });
    });

    // ── EXPORT ──────────────────────────────────────────────
    const postGroups   = document.getElementById('sb-post-groups');
    const toggleAllBtn = document.getElementById('sb-toggle-all');
    const countLabel   = document.getElementById('sb-selected-count');
    const loadingLabel = document.getElementById('sb-loading-posts');
    const exportBtn    = document.getElementById('sb-export-btn');
    const exportForm   = document.getElementById('sb-export-form');
    const exportResult = document.getElementById('sb-export-result');

    function updateCount() {
        if (!postGroups) return;
        const checked = postGroups.querySelectorAll('input[name="post_ids[]"]:checked').length;
        const total   = postGroups.querySelectorAll('input[name="post_ids[]"]').length;
        countLabel.textContent = checked + ' ausgewählt';
        if (exportBtn) exportBtn.disabled = checked === 0;
        if (toggleAllBtn) toggleAllBtn.textContent = (checked === total && total > 0) ? 'Alle abwählen' : 'Alle auswählen';
    }

    function updateGroupToggle(groupEl) {
        const groupCb  = groupEl.querySelector('.sb-group-all');
        const itemCbs  = groupEl.querySelectorAll('input[name="post_ids[]"]');
        const checked  = groupEl.querySelectorAll('input[name="post_ids[]"]:checked').length;
        if (groupCb) groupCb.indeterminate = checked > 0 && checked < itemCbs.length;
        if (groupCb) groupCb.checked = checked === itemCbs.length && itemCbs.length > 0;
    }

    function loadAllPosts() {
        if (!postGroups) return;
        if (loadingLabel) loadingLabel.style.display = 'inline';
        postGroups.innerHTML = '';
        if (exportBtn) exportBtn.disabled = true;

        const data = new FormData();
        data.append('action', 'sb_get_all_posts');
        data.append('nonce', siteBackup.allPostsNonce);

        fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(function (res) {
                if (loadingLabel) loadingLabel.style.display = 'none';
                if (!res.success || !Object.keys(res.data).length) {
                    postGroups.innerHTML = '<p>Keine Posts gefunden.</p>';
                    return;
                }
                Object.entries(res.data).forEach(function ([type, group]) {
                    const details = document.createElement('details');
                    details.className = 'sb-pt-group';
                    details.open = true;

                    const count = group.posts.length;
                    details.innerHTML =
                        '<summary class="sb-pt-summary">'
                        + '<label class="sb-group-label" onclick="event.stopPropagation()">'
                        + '<input type="checkbox" class="sb-group-all" data-group="' + type + '"> '
                        + '<strong>' + group.label + '</strong>'
                        + '</label>'
                        + '<span class="sb-pt-count">' + count + ' Posts</span>'
                        + '</summary>'
                        + '<div class="sb-post-list">'
                        + group.posts.map(function (p) {
                            return '<label class="sb-post-item">'
                                + '<input type="checkbox" name="post_ids[]" value="' + p.id + '">'
                                + ' <span class="sb-post-title">' + p.title + '</span>'
                                + ' <span class="sb-post-meta">' + p.date + ' · ' + p.status + '</span>'
                                + '</label>';
                        }).join('')
                        + '</div>';

                    // Gruppen-Checkbox: alle in Gruppe toggeln
                    const groupCb = details.querySelector('.sb-group-all');
                    groupCb.addEventListener('change', function () {
                        details.querySelectorAll('input[name="post_ids[]"]').forEach(cb => {
                            cb.checked = groupCb.checked;
                        });
                        updateCount();
                    });

                    // Einzel-Checkbox → Gruppen-Toggle + Gesamt-Zähler
                    details.querySelectorAll('input[name="post_ids[]"]').forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            updateGroupToggle(details);
                            updateCount();
                        });
                    });

                    postGroups.appendChild(details);
                });

                updateCount();
            })
            .catch(function () {
                if (loadingLabel) loadingLabel.style.display = 'none';
                postGroups.innerHTML = '<p class="sb-error">Fehler beim Laden der Posts.</p>';
            });
    }

    // Globaler Toggle
    if (toggleAllBtn) {
        toggleAllBtn.addEventListener('click', function () {
            const allCbs = postGroups.querySelectorAll('input[name="post_ids[]"]');
            const anyUnchecked = [...allCbs].some(cb => !cb.checked);
            allCbs.forEach(cb => { cb.checked = anyUnchecked; });
            postGroups.querySelectorAll('.sb-pt-group').forEach(updateGroupToggle);
            updateCount();
        });
    }

    // Export-Submit
    if (exportForm) {
        exportForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var postsPerZip = 10;
            var postsPerZipInput = document.getElementById('sb-posts-per-zip');
            if (postsPerZipInput && postsPerZipInput.value) {
                postsPerZip = parseInt(postsPerZipInput.value, 10) || 10;
                if (postsPerZip < 1) postsPerZip = 1;
                if (postsPerZip > 50) postsPerZip = 50;
            }

            var selectedPosts = postGroups.querySelectorAll('input[name="post_ids[]"]:checked');
            if (!selectedPosts.length) {
                exportResult.innerHTML = '<p class="sb-error">Bitte Posts auswählen.</p>';
                return;
            }

            var postIds = [].slice.call(selectedPosts).map(function(cb) { return parseInt(cb.value, 10); }).filter(function(id) { return id > 0; });
            if (!postIds.length) {
                exportResult.innerHTML = '<p class="sb-error">Bitte Posts auswählen.</p>';
                return;
            }
            var chunks = [];
            for (var i = 0; i < postIds.length; i += postsPerZip) {
                chunks.push(postIds.slice(i, i + postsPerZip));
            }

            var allUrls = [];
            var totalCount = 0;
            var currentPart = 0;
            var totalParts = chunks.length;

            exportResult.innerHTML = '<p>Exportiere... <span id="sb-export-progress">0/' + totalParts + '</span></p><div style="width:100%;background:#eee;height:20px;margin:10px 0;"><div id="sb-export-bar" style="width:0%;background:#2271b1;height:100%;transition:width 0.3s;"></div></div>';

            var exportBar = document.getElementById('sb-export-bar');
            var exportProgress = document.getElementById('sb-export-progress');

            function exportNextPart() {
                if (currentPart >= totalParts) {
                    exportResult.innerHTML = '<p class="sb-success">' + totalCount + ' Posts in ' + totalParts + ' ZIPs exportiert:</p><p>' +
                        allUrls.map(function(url, i) { return '<a href="' + url + '" download>ZIP ' + (i+1) + '</a>'; }).join(' &nbsp; ') + '</p>';
                    return;
                }

                var data = new FormData();
                data.append('action', 'sb_export_part');
                data.append('nonce', siteBackup.nonce);
                data.append('max_mb', siteBackup.splitMaxMb || 50);
                data.append('posts_per_zip', postsPerZip);
                chunks[currentPart].forEach(function(id) { data.append('post_ids[]', id); });

                fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (!res.success) {
                            exportResult.innerHTML = '<p class="sb-error">Fehler bei Part ' + (currentPart+1) + ': ' + (res.data && res.data.message || 'Error') + '</p>';
                            return;
                        }
                        allUrls = allUrls.concat(res.data.urls || []);
                        totalCount += res.data.count || 0;
                        currentPart++;

                        var pct = Math.round((currentPart / totalParts) * 100);
                        exportBar.style.width = pct + '%';
                        exportProgress.textContent = currentPart + '/' + totalParts;

                        exportNextPart();
                    })
                    .catch(function(err) {
                        exportResult.innerHTML = '<p class="sb-error">Netzwerkfehler bei Part ' + (currentPart+1) + '</p>';
                    });
            }

            exportNextPart();
        });
    }

    // Init
    loadAllPosts();

    // Import-Form with Chunked Upload
    var importForm = document.getElementById('sb-import-form');
    var CHUNK_SIZE = 10 * 1024 * 1024;

    function doChunkedUpload(file, onManifest) {
        var result = document.getElementById('sb-import-result');
        var filename = file.name;
        var fileSize = file.size;
        var chunkCount = Math.ceil(fileSize / CHUNK_SIZE);
        var uploadedChunks = 0;

        result.innerHTML = '<p>Chunked Upload: 0/' + chunkCount + ' Chunks…</p>';

        function uploadNextChunk(index) {
            var start = index * CHUNK_SIZE;
            var end = Math.min(start + CHUNK_SIZE, fileSize);
            var chunk = file.slice(start, end);
            var reader = new FileReader();
            reader.onload = function (e) {
                var base64 = btoa(String.fromCharCode.apply(null, new Uint8Array(e.target.result)));
                var data = new FormData();
                data.append('action', 'sb_chunk_append');
                data.append('nonce', siteBackup.importNonce);
                data.append('filename', filename);
                data.append('chunk_index', index);
                data.append('chunk_data', base64);

                fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) {
                            result.innerHTML = '<p class="sb-error">Chunk ' + index + ' failed: ' + (res.data && res.data.message || 'Error') + '</p>';
                            return;
                        }
                        uploadedChunks++;
                        result.innerHTML = '<p>Chunked Upload: ' + uploadedChunks + '/' + chunkCount + ' Chunks…</p>';
                        if (index + 1 < chunkCount) {
                            uploadNextChunk(index + 1);
                        } else {
                            var mergeData = new FormData();
                            mergeData.append('action', 'sb_chunk_merge');
                            mergeData.append('nonce', siteBackup.importNonce);
                            mergeData.append('filename', filename);
                            mergeData.append('total_chunks', chunkCount);
                            fetch(siteBackup.ajaxUrl, { method: 'POST', body: mergeData })
                                .then(function (r) { return r.json(); })
                                .then(function (res) {
                                    if (!res.success) {
                                        result.innerHTML = '<p class="sb-error">Merge failed: ' + (res.data && res.data.message || 'Error') + '</p>';
                                        return;
                                    }
                                    onManifest(res.data.post_types);
                                })
                                .catch(function () {
                                    result.innerHTML = '<p class="sb-error">Merge network error.</p>';
                                });
                        }
                    })
                    .catch(function () {
                        result.innerHTML = '<p class="sb-error">Chunk network error.</p>';
                    });
            };
            reader.readAsArrayBuffer(chunk);
        }

        var initData = new FormData();
        initData.append('action', 'sb_chunk_init');
        initData.append('nonce', siteBackup.importNonce);
        initData.append('filename', filename);
        initData.append('total_chunks', chunkCount);

        fetch(siteBackup.ajaxUrl, { method: 'POST', body: initData })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    result.innerHTML = '<p class="sb-error">Init failed: ' + (res.data && res.data.message || 'Error') + '</p>';
                    return;
                }
                uploadNextChunk(0);
            })
            .catch(function () {
                result.innerHTML = '<p class="sb-error">Init network error.</p>';
            });
    }

    if (importForm) {
        importForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var result = document.getElementById('sb-import-result');

            var zipFile = importForm.querySelector('input[name="sb_zip"]');
            var serverFile = importForm.querySelector('input[name="sb_zip_file"]');

            var fileList = [];
            var serverFiles = [];

            if (serverFile && serverFile.value) {
                serverFiles = serverFile.value.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s; });
            }

            if (zipFile && zipFile.files && zipFile.files.length) {
                for (var i = 0; i < zipFile.files.length; i++) {
                    fileList.push({ name: zipFile.files[i].name, file: zipFile.files[i], source: 'upload' });
                }
            }

            serverFiles.forEach(function(name) {
                fileList.push({ name: name, source: 'server' });
            });

            if (!fileList.length) {
                result.innerHTML = '<p class="sb-error">Bitte mindestens eine ZIP-Datei auswählen.</p>';
                return;
            }

            var currentFile = 0;
            var totalFiles = fileList.length;
            var allResults = { created: [], updated: [], skipped: [], skipped_mapped: [], skipped_no_cpt: [], errors: [] };

            result.innerHTML = '<p>Importiere... <span id="sb-import-progress">0/' + totalFiles + '</span></p>' +
                '<div style="width:100%;background:#eee;height:20px;margin:10px 0;">' +
                '<div id="sb-import-bar" style="width:0%;background:#2271b1;height:100%;transition:width 0.3s;"></div></div>' +
                '<div id="sb-import-log" style="font-size:0.9em;color:#666;"></div>';

            var importBar = document.getElementById('sb-import-bar');
            var importProgress = document.getElementById('sb-import-progress');
            var importLog = document.getElementById('sb-import-log');

            function processNextFile() {
                if (currentFile >= totalFiles) {
                    var summary = '<p class="sb-success"><strong>Insgesamt: Erstellt: ' + allResults.created.length +
                        ' | Aktualisiert: ' + allResults.updated.length +
                        ' | Übersprungen: ' + allResults.skipped.length + '</strong></p>';
                    var makeList = function (label, items) {
                        return items.length
                            ? '<details><summary>' + label + ' (' + items.length + ')</summary><ul>' + items.map(function (t) { return '<li>' + t + '</li>'; }).join('') + '</ul></details>'
                            : '';
                    };
                    result.innerHTML = summary
                        + makeList('Erstellt', allResults.created)
                        + makeList('Aktualisiert', allResults.updated)
                        + makeList('Übersprungen', allResults.skipped)
                        + makeList('Übersprungen (Mapping)', allResults.skipped_mapped)
                        + makeList('Übersprungen (CPT)', allResults.skipped_no_cpt)
                        + (allResults.errors.length ? makeList('Fehler', allResults.errors) : '');
                    return;
                }

                var f = fileList[currentFile];
                importLog.textContent = 'Verarbeite: ' + f.name;

                if (f.source === 'server') {
                    var data = new FormData(importForm);
                    data.append('action', 'sb_import');
                    data.append('nonce', siteBackup.importNonce);
                    data.set('sb_zip_file', f.name);
                    data.delete('sb_zip');

                    fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res.success) {
                                allResults.errors.push(f.name + ': ' + (res.data && res.data.message || 'Error'));
                            } else {
                                allResults.created = allResults.created.concat(res.data.created || []);
                                allResults.updated = allResults.updated.concat(res.data.updated || []);
                                allResults.skipped = allResults.skipped.concat(res.data.skipped || []);
                                allResults.skipped_mapped = allResults.skipped_mapped.concat(res.data.skipped_mapped || []);
                                allResults.skipped_no_cpt = allResults.skipped_no_cpt.concat(res.data.skipped_no_cpt || []);
                                if (res.data.errors && res.data.errors.length) {
                                    allResults.errors = allResults.errors.concat(res.data.errors);
                                }
                            }
                            finishFile();
                        })
                        .catch(function () {
                            allResults.errors.push(f.name + ': Netzwerkfehler');
                            finishFile();
                        });
                } else {
                    var file = f.file;
                    if (file.size > CHUNK_SIZE) {
                        doChunkedUpload(file, function (postTypes) {
                            showCptMapping(postTypes, function() {
                                var data = new FormData();
                                data.append('action', 'sb_import');
                                data.append('nonce', siteBackup.importNonce);
                                data.append('sb_zip_file', file.name);
                                data.append('collision', importForm.querySelector('#sb-collision').value);
                                var cptMappingDiv = document.getElementById('sb-cpt-mapping');
                                if (cptMappingDiv && cptMappingDiv.style.display !== 'none') {
                                    var selects = cptMappingDiv.querySelectorAll('select');
                                    selects.forEach(function(sel) {
                                        data.append('cpt_map[' + sel.dataset.srcType + ']', sel.value);
                                    });
                                }

                                fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                                    .then(function (r) { return r.json(); })
                                    .then(function (res) {
                                        if (!res.success) {
                                            allResults.errors.push(f.name + ': ' + (res.data && res.data.message || 'Error'));
                                        } else {
                                            allResults.created = allResults.created.concat(res.data.created || []);
                                            allResults.updated = allResults.updated.concat(res.data.updated || []);
                                            allResults.skipped = allResults.skipped.concat(res.data.skipped || []);
                                            allResults.skipped_mapped = allResults.skipped_mapped.concat(res.data.skipped_mapped || []);
                                            allResults.skipped_no_cpt = allResults.skipped_no_cpt.concat(res.data.skipped_no_cpt || []);
                                            if (res.data.errors && res.data.errors.length) {
                                                allResults.errors = allResults.errors.concat(res.data.errors);
                                            }
                                        }
                                        finishFile();
                                    })
                                    .catch(function () {
                                        allResults.errors.push(f.name + ': Netzwerkfehler');
                                        finishFile();
                                    });
                            });
                        });
                    } else {
                        var data = new FormData(importForm);
                        data.append('action', 'sb_import');
                        data.append('nonce', siteBackup.importNonce);

                        fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                if (!res.success) {
                                    allResults.errors.push(f.name + ': ' + (res.data && res.data.message || 'Error'));
                                } else {
                                    allResults.created = allResults.created.concat(res.data.created || []);
                                    allResults.updated = allResults.updated.concat(res.data.updated || []);
                                    allResults.skipped = allResults.skipped.concat(res.data.skipped || []);
                                    allResults.skipped_mapped = allResults.skipped_mapped.concat(res.data.skipped_mapped || []);
                                    allResults.skipped_no_cpt = allResults.skipped_no_cpt.concat(res.data.skipped_no_cpt || []);
                                    if (res.data.errors && res.data.errors.length) {
                                        allResults.errors = allResults.errors.concat(res.data.errors);
                                    }
                                }
                                finishFile();
                            })
                            .catch(function () {
                                allResults.errors.push(f.name + ': Netzwerkfehler');
                                finishFile();
                            });
                    }
                }
            }

            function finishFile() {
                currentFile++;
                var pct = Math.round((currentFile / totalFiles) * 100);
                importBar.style.width = pct + '%';
                importProgress.textContent = currentFile + '/' + totalFiles;
                processNextFile();
            }

            processNextFile();
        });
    }

    function showImportResult(result, d) {
        var summary = '<p class="sb-success"><strong>Erstellt: ' + d.created.length + ' | Aktualisiert: ' + d.updated.length + ' | Übersprungen: ' + d.skipped.length + '</strong></p>';
        var makeList = function (label, items) {
            return items.length
                ? '<details><summary>' + label + ' (' + items.length + ')</summary><ul>' + items.map(function (t) { return '<li>' + t + '</li>'; }).join('') + '</ul></details>'
                : '';
        };
        result.innerHTML = summary
            + makeList('Erstellt', d.created)
            + makeList('Aktualisiert', d.updated)
            + makeList('Übersprungen', d.skipped)
            + makeList('Übersprungen (Mapping)', d.skipped_mapped || [])
            + makeList('Übersprungen (CPT)', d.skipped_no_cpt || [])
            + (d.errors && d.errors.length ? makeList('Fehler', d.errors) : '');
    }

    function showCptMapping(postTypes, onConfirm) {
        var cptMappingDiv = document.getElementById('sb-cpt-mapping');
        var cptMapTable = document.getElementById('sb-cpt-map-table');
        if (!cptMappingDiv || !cptMapTable) {
            if (onConfirm) onConfirm();
            return;
        }

        var available = (siteBackup.availableCpts || []);
        var availableNames = available.map(function (c) { return c.name; });

        var rows = postTypes.map(function (srcType) {
            var exists = availableNames.indexOf(srcType) !== -1;
            var options = available.map(function (c) {
                return '<option value="' + c.name + '"' + (c.name === srcType ? ' selected' : '') + '>' + c.label + ' (' + c.name + ')</option>';
            }).join('');
            return '<tr' + (!exists ? ' class="sb-cpt-missing"' : '') + '>'
                + '<td><code>' + srcType + '</code>' + (!exists ? ' <span style="color:#d63638;">⚇ nicht gefunden</span>' : '') + '</td>'
                + '<td><select name="cpt_map[' + srcType + ']" data-src-type="' + srcType + '" class="' + (!exists ? 'sb-cpt-missing' : '') + '">' + options + '<option value="skip">— Überspringen —</option></select></td>'
                + '</tr>';
        }).join('');

        cptMapTable.querySelector('tbody').innerHTML = rows;

        if (!postTypes || !postTypes.length) {
            if (onConfirm) onConfirm();
            return;
        }

        cptMappingDiv.style.display = 'block';
        cptMappingDiv.dataset.waitingConfirm = '1';
        cptMappingDiv.dataset.onConfirm = onConfirm ? '1' : '0';

        var existingBtn = cptMappingDiv.querySelector('.button-primary');
        if (existingBtn) existingBtn.remove();

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button button-primary';
        btn.style.marginTop = '10px';
        btn.textContent = 'Import fortsetzen';
        btn.onclick = function() {
            cptMappingDiv.style.display = 'none';
            cptMappingDiv.dataset.waitingConfirm = '0';
            if (onConfirm) onConfirm();
        };
        cptMappingDiv.appendChild(btn);
    }

    // ── CPT-MAPPING (Import) ──────────────────────────────────────────────────────
    var zipInput = document.querySelector('#sb-tab-import input[type="file"][name="sb_zip"]');
    var serverZipInput = document.querySelector('#sb-tab-import input[name="sb_zip_file"]');
    var cptMappingDiv = document.getElementById('sb-cpt-mapping');
    var cptMapTable = document.getElementById('sb-cpt-map-table');

    function doPeek(fileOrPath) {
        if (!cptMappingDiv || !cptMapTable) return;

        cptMappingDiv.style.display = 'none';
        cptMapTable.querySelector('tbody').innerHTML = '<tr><td colspan="2">Lese ZIP…</td></tr>';
        var data = new FormData();
        data.append('action', 'sb_peek_manifest');
        data.append('nonce', siteBackup.peekNonce);
    const exportUsersBtn    = document.getElementById('sb-export-users-btn');
    const exportUsersResult = document.getElementById('sb-export-users-result');
    const usersRoleSelect   = document.getElementById('sb-users-role');
    const importUsersBtn    = document.getElementById('sb-import-users-btn');
    const importUsersResult = document.getElementById('sb-import-users-result');
    const usersZipInput     = document.getElementById('sb-users-zip');

    if (exportUsersBtn) {
        exportUsersBtn.addEventListener('click', function () {
            exportUsersResult.innerHTML = '<p>Exportiere Benutzer…</p>';
            const data = new FormData();
            data.append('action', 'sb_export_users');
            data.append('nonce', siteBackup.exportUsersNonce);
            data.append('role', usersRoleSelect ? usersRoleSelect.value : '');

            fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (res) {
                    if (!res.success) {
                        exportUsersResult.innerHTML = '<p class="sb-error">Fehler: ' + (res.data?.message || 'Unbekannter Fehler') + '</p>';
                        return;
                    }
                    exportUsersResult.innerHTML = '<p class="sb-success">'
                        + res.data.count + ' Benutzer exportiert. '
                        + '<a href="' + res.data.url + '" download>ZIP herunterladen</a></p>';
                })
                .catch(function () {
                    exportUsersResult.innerHTML = '<p class="sb-error">Netzwerkfehler.</p>';
                });
        });
    }

    if (importUsersBtn) {
        importUsersBtn.addEventListener('click', function () {
            if (!usersZipInput || !usersZipInput.files.length) {
                importUsersResult.innerHTML = '<p class="sb-error">Bitte ZIP-Datei auswählen.</p>';
                return;
            }
            importUsersResult.innerHTML = '<p>Importiere Benutzer…</p>';
            const data = new FormData();
            data.append('action', 'sb_import_users');
            data.append('nonce', siteBackup.importUsersNonce);
            data.append('sb_users_zip', usersZipInput.files[0]);

            fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (res) {
                    if (!res.success) {
                        importUsersResult.innerHTML = '<p class="sb-error">Fehler: ' + (res.data?.message || 'Unbekannter Fehler') + '</p>';
                        return;
                    }
                    const d = res.data;
                    let html = '<p class="sb-success"><strong>Erstellt: ' + d.created.length
                        + ' | Aktualisiert: ' + d.updated.length + '</strong></p>';
                    if (d.password_reset_hint) {
                        html += '<div class="sb-warning">⚠️ Neu angelegte Benutzer haben ein Zufallspasswort. '
                            + 'Bitte Passwort-Reset-Links versenden '
                            + '(<a href="' + siteBackup.ajaxUrl.replace('admin-ajax.php', '') + 'users.php" target="_blank">Benutzerliste öffnen</a>).</div>';
                    }
                    if (d.created.length) html += '<details><summary>Erstellt (' + d.created.length + ')</summary><ul>' + d.created.map(n => '<li>' + n + '</li>').join('') + '</ul></details>';
                    if (d.updated.length) html += '<details><summary>Aktualisiert (' + d.updated.length + ')</summary><ul>' + d.updated.map(n => '<li>' + n + '</li>').join('') + '</ul></details>';
                    if (d.errors && d.errors.length) html += '<details><summary>Fehler (' + d.errors.length + ')</summary><ul>' + d.errors.map(n => '<li>' + n + '</li>').join('') + '</ul></details>';
                    importUsersResult.innerHTML = html;
                })
                .catch(function () {
                    importUsersResult.innerHTML = '<p class="sb-error">Netzwerkfehler.</p>';
                });
        });
    }

    // ── SETTINGS ─────────────────────────────────────────────────────────────────
    const exportSettingsBtn    = document.getElementById('sb-export-settings-btn');
    const exportSettingsResult = document.getElementById('sb-export-settings-result');
    const importSettingsBtn    = document.getElementById('sb-import-settings-btn');
    const importSettingsResult = document.getElementById('sb-import-settings-result');
    const settingsZipInput     = document.getElementById('sb-settings-zip');
    const selectAllSettingsBtn = document.getElementById('sb-select-all-settings');

    if (selectAllSettingsBtn) {
        selectAllSettingsBtn.addEventListener('click', function () {
            const allCbs = document.querySelectorAll('input[name="sb_setting_keys[]"]');
            const anyUnchecked = [...allCbs].some(cb => !cb.checked);
            allCbs.forEach(cb => { cb.checked = anyUnchecked; });
            selectAllSettingsBtn.textContent = anyUnchecked ? 'Alle abwählen' : 'Alle auswählen';
        });
    }

    if (exportSettingsBtn) {
        exportSettingsBtn.addEventListener('click', function () {
            const checked = document.querySelectorAll('input[name="sb_setting_keys[]"]:checked');
            if (!checked.length) {
                exportSettingsResult.innerHTML = '<p class="sb-error">Bitte mindestens eine Einstellung auswählen.</p>';
                return;
            }
            exportSettingsResult.innerHTML = '<p>Exportiere Einstellungen…</p>';
            const data = new FormData();
            data.append('action', 'sb_export_settings');
            data.append('nonce', siteBackup.exportSettingsNonce);
            checked.forEach(cb => data.append('keys[]', cb.value));

            fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (res) {
                    if (!res.success) {
                        exportSettingsResult.innerHTML = '<p class="sb-error">Fehler: ' + (res.data?.message || 'Fehler') + '</p>';
                        return;
                    }
                    exportSettingsResult.innerHTML = '<p class="sb-success">'
                        + res.data.count + ' Einstellungen exportiert. '
                        + '<a href="' + res.data.url + '" download>ZIP herunterladen</a></p>';
                })
                .catch(function () {
                    exportSettingsResult.innerHTML = '<p class="sb-error">Netzwerkfehler.</p>';
                });
        });
    }

    if (importSettingsBtn) {
        importSettingsBtn.addEventListener('click', function () {
            if (!settingsZipInput || !settingsZipInput.files.length) {
                importSettingsResult.innerHTML = '<p class="sb-error">Bitte ZIP-Datei auswählen.</p>';
                return;
            }
            importSettingsResult.innerHTML = '<p>Importiere Einstellungen…</p>';
            const data = new FormData();
            data.append('action', 'sb_import_settings');
            data.append('nonce', siteBackup.importSettingsNonce);
            data.append('sb_settings_zip', settingsZipInput.files[0]);

            fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (res) {
                    if (!res.success) {
                        importSettingsResult.innerHTML = '<p class="sb-error">Fehler: ' + (res.data?.message || 'Fehler') + '</p>';
                        return;
                    }
                    const d = res.data;
                    let html = '<p class="sb-success"><strong>' + d.updated.length + ' Einstellungen importiert.</strong></p>';
                    if (d.skipped_protected && d.skipped_protected.length) {
                        html += '<div class="sb-warning">⚠️ Folgende Einstellungen wurden <strong>nicht</strong> überschrieben (geschützt): '
                            + d.skipped_protected.map(k => '<code>' + k + '</code>').join(', ') + '</div>';
                    }
                    if (d.skipped_invalid && d.skipped_invalid.length) {
                        html += '<div class="sb-warning">Unbekannte Keys übersprungen: '
                            + d.skipped_invalid.map(k => '<code>' + k + '</code>').join(', ') + '</div>';
                    }
                    importSettingsResult.innerHTML = html;
                })
                .catch(function () {
                    importSettingsResult.innerHTML = '<p class="sb-error">Netzwerkfehler.</p>';
                });
        });
    }

}); // DOMContentLoaded
