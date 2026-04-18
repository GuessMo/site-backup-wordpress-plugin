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

    // Post-Auswahl
    const postTypeSelect = document.getElementById('sb-post-type');
    const postListWrap = document.getElementById('sb-post-list-wrap');
    const postList = document.getElementById('sb-post-list');
    const toggleBtn = document.getElementById('sb-toggle-all');
    const countLabel = document.getElementById('sb-selected-count');
    const exportBtn = document.getElementById('sb-export-btn');

    function updateCount() {
        const checked = postList.querySelectorAll('input[type="checkbox"]:checked').length;
        countLabel.textContent = checked + ' ausgewählt';
        exportBtn.disabled = checked === 0;
        const all = postList.querySelectorAll('input[type="checkbox"]').length;
        toggleBtn.textContent = checked === all ? 'Alle abwählen' : 'Alle auswählen';
    }

    function loadPosts(postType) {
        postListWrap.style.display = 'none';
        postList.innerHTML = '<p>Lade Posts…</p>';
        exportBtn.disabled = true;

        const data = new FormData();
        data.append('action', 'sb_get_posts');
        data.append('nonce', siteBackup.postsNonce);
        data.append('post_type', postType);

        fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(function(res) {
                if (!res.success || !res.data.length) {
                    postList.innerHTML = '<p>Keine Posts gefunden.</p>';
                    postListWrap.style.display = 'block';
                    return;
                }
                postList.innerHTML = res.data.map(function(p) {
                    return '<label class="sb-post-item">'
                        + '<input type="checkbox" name="post_ids[]" value="' + p.id + '">'
                        + ' <span class="sb-post-title">' + p.title + '</span>'
                        + ' <span class="sb-post-meta">' + p.date + ' · ' + p.status + '</span>'
                        + '</label>';
                }).join('');
                postListWrap.style.display = 'block';
                postList.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                    cb.addEventListener('change', updateCount);
                });
                updateCount();
            })
            .catch(function() {
                postList.innerHTML = '<p class="sb-error">Fehler beim Laden.</p>';
                postListWrap.style.display = 'block';
            });
    }

    if (postTypeSelect) {
        postTypeSelect.addEventListener('change', function() {
            loadPosts(this.value);
        });
        loadPosts(postTypeSelect.value);
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            const checkboxes = postList.querySelectorAll('input[type="checkbox"]');
            const allChecked = [...checkboxes].every(cb => cb.checked);
            checkboxes.forEach(cb => { cb.checked = !allChecked; });
            updateCount();
        });
    }

    // Export-Form
    const form = document.getElementById('sb-export-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const result = document.getElementById('sb-export-result');
        result.innerHTML = '<p>Export wird erstellt…</p>';

        const data = new FormData(form);
        data.append('action', 'sb_export');
        data.append('nonce', siteBackup.nonce);

        fetch(siteBackup.ajaxUrl, { method: 'POST', body: data })
            .then(r => r.json())
            .then(function (res) {
                if (!res.success) {
                    result.innerHTML = '<p class="sb-error">Fehler: ' + (res.data?.message || 'Unbekannter Fehler') + '</p>';
                    return;
                }
                const d = res.data;
                const titles = d.titles.map(t => '<li>' + t + '</li>').join('');
                result.innerHTML =
                    '<p class="sb-success"><strong>' + d.count + ' Posts exportiert.</strong></p>' +
                    '<ul class="sb-export-list">' + titles + '</ul>' +
                    '<p><a href="' + d.download_url + '" class="button button-secondary" download>ZIP herunterladen</a></p>';
            })
            .catch(function () {
                result.innerHTML = '<p class="sb-error">Netzwerkfehler beim Export.</p>';
            });
    });

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
