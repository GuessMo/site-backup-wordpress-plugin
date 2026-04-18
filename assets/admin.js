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
                    exportResult.innerHTML = '<p class="sb-success">Export erfolgreich! '
                        + '<a href="' + res.data.url + '" download>ZIP herunterladen</a></p>';
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
                        + (d.errors?.length ? makeList('Fehler', d.errors) : '');
                })
                .catch(function () {
                    result.innerHTML = '<p class="sb-error">Netzwerkfehler beim Import.</p>';
                });
        });
    }
});
