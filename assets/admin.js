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
            exportResult.innerHTML = '<p>Export läuft…</p>';

            const data = new FormData();
            data.append('action', 'sb_export');
            data.append('nonce', siteBackup.nonce);
            data.append('max_mb', siteBackup.splitMaxMb || 50);
            postGroups.querySelectorAll('input[name="post_ids[]"]:checked').forEach(function (cb) {
                data.append('post_ids[]', cb.value);
            });

            fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (res) {
                    if (!res.success) {
                        exportResult.innerHTML = '<p class="sb-error">Fehler: ' + (res.data?.message || 'Unbekannter Fehler') + '</p>';
                        return;
                    }
                    const urls = res.data.urls || [];
                    if (urls.length === 1) {
                        exportResult.innerHTML = '<p class="sb-success">Export erfolgreich! '
                            + '<a href="' + urls[0] + '" download>Export herunterladen</a></p>';
                    } else {
                        const links = urls.map(function (url, i) {
                            return '<a href="' + url + '" download>Teil ' + (i + 1) + ' herunterladen</a>';
                        }).join(' &nbsp; ');
                        exportResult.innerHTML = '<p class="sb-success">Export erfolgreich! (' + urls.length + ' Teile)</p>'
                            + '<p>' + links + '</p>';
                    }
                })
                .catch(function () {
                    exportResult.innerHTML = '<p class="sb-error">Netzwerkfehler beim Export.</p>';
                });
        });
    }

    // Init
    loadAllPosts();

    // Import-Form
    const importForm = document.getElementById('sb-import-form');
    if (importForm) {
        importForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const result = document.getElementById('sb-import-result');

            const zipFile = importForm.querySelector('input[name="sb_zip"]');
            if (!zipFile || !zipFile.files.length) {
                result.innerHTML = '<p class="sb-error">Bitte eine ZIP-Datei auswählen.</p>';
                return;
            }

            result.innerHTML = '<p>Import läuft…</p>';

            const data = new FormData(importForm);
            data.append('action', 'sb_import');
            data.append('nonce', siteBackup.importNonce);

            fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (res) {
                    if (!res.success) {
                        result.innerHTML = '<p class="sb-error">Fehler: ' + (res.data?.message || 'Unbekannter Fehler') + '</p>';
                        return;
                    }
                    const d = res.data;
                    const summary = '<p class="sb-success"><strong>Erstellt: ' + d.created.length +
                        ' | Aktualisiert: ' + d.updated.length +
                        ' | Übersprungen: ' + d.skipped.length + '</strong></p>';
                    const makeList = (label, items) => items.length
                        ? '<details><summary>' + label + ' (' + items.length + ')</summary><ul>' +
                          items.map(t => '<li>' + t + '</li>').join('') + '</ul></details>'
                        : '';
                    result.innerHTML = summary
                        + makeList('Erstellt', d.created)
                        + makeList('Aktualisiert', d.updated)
                        + makeList('Übersprungen', d.skipped)
                        + makeList('Übersprungen (Mapping)', d.skipped_mapped || [])
                        + makeList('Übersprungen (CPT fehlt)', d.skipped_no_cpt || [])
                        + (d.errors?.length ? makeList('Fehler', d.errors) : '');
                })
                .catch(function () {
                    result.innerHTML = '<p class="sb-error">Netzwerkfehler beim Import.</p>';
                });
        });
    }

    // ── CPT-MAPPING (Import) ──────────────────────────────────────────────────────
    const zipInput      = document.querySelector('#sb-tab-import input[type="file"][name="sb_zip"]');
    const cptMappingDiv = document.getElementById('sb-cpt-mapping');
    const cptMapTable   = document.getElementById('sb-cpt-map-table');

    if (zipInput && cptMappingDiv && cptMapTable) {
        zipInput.addEventListener('change', function () {
            if (!this.files.length) {
                cptMappingDiv.style.display = 'none';
                return;
            }

            const data = new FormData();
            data.append('action', 'sb_peek_manifest');
            data.append('nonce', siteBackup.peekNonce);
            data.append('sb_zip', this.files[0]);

            cptMappingDiv.style.display = 'none';
            cptMapTable.querySelector('tbody').innerHTML = '<tr><td colspan="2">Lese ZIP…</td></tr>';

            fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
                .then(r => r.json())
                .then(function (res) {
                    if (!res.success || !res.data.post_types || !res.data.post_types.length) {
                        return;
                    }
                    const available = (siteBackup.availableCpts || []);
                    const availableNames = available.map(c => c.name);

                    const rows = res.data.post_types.map(function (srcType) {
                        const exists = availableNames.includes(srcType);
                        const options = available.map(c =>
                            '<option value="' + c.name + '"' + (c.name === srcType ? ' selected' : '') + '>'
                            + c.label + ' (' + c.name + ')'
                            + '</option>'
                        ).join('');
                        return '<tr' + (!exists ? ' class="sb-cpt-missing"' : '') + '>'
                            + '<td><code>' + srcType + '</code>'
                            + (!exists ? ' <span style="color:#d63638;">&#9888; nicht gefunden</span>' : '')
                            + '</td>'
                            + '<td><select name="cpt_map[' + srcType + ']" class="' + (!exists ? 'sb-cpt-missing' : '') + '">'
                            + options
                            + '<option value="skip">— Überspringen —</option>'
                            + '</select></td>'
                            + '</tr>';
                    }).join('');

                    cptMapTable.querySelector('tbody').innerHTML = rows;
                    cptMappingDiv.style.display = 'block';
                })
                .catch(function () {
                    // kein Peek möglich → still ignorieren, Import läuft ohne Mapping
                });
        });
    }

    // ── USERS ────────────────────────────────────────────────────────────────────
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
