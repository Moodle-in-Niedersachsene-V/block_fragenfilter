// AMD module for the question bank filter (block_fragenfilter).
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    // Question types are loaded dynamically; this map only provides
    // fallback display names if get_questiontypes returns no match.
    var QTYPE_FALLBACK = {
        multichoice: 'Multiple Choice', truefalse: 'Wahr/Falsch',
        shortanswer: 'Kurzantwort', numerical: 'Numerisch',
        essay: 'Freitext', match: 'Zuordnung',
        ddwtos: 'Drag & Drop', gapselect: 'Lückentext',
        calculated: 'Berechnet', description: 'Beschreibung',
        stack: 'STACK', kprime: 'KPrime',
        mtf: 'MTF', recordrtc: 'Audioaufnahme',
    };
    var TYPE_COLOR = {
        multichoice: 'bg-success bg-opacity-10 text-success',
        truefalse: 'bg-info bg-opacity-10 text-info',
        shortanswer: 'bg-warning bg-opacity-10 text-warning',
        numerical: 'bg-primary bg-opacity-10 text-primary',
        essay: 'bg-secondary bg-opacity-10 text-secondary',
        match: 'bg-info bg-opacity-10 text-info',
    };

    /**
     * State object for a block instance.
     *
     * @param {Number} blockid Block instance ID.
     * @param {Object} config Configuration from PHP.
     */
    function BlockState(blockid, config) {
        this.blockid = blockid;
        this.config = config;
        this.search = '';
        this.cats = {};
        this.types = {};
        this.diffs = {};
        this.tags = [];
        this.selected = {};
        this.results = [];
        this.allCats = [];
        this.loadedQtypes = []; // [{key, label}] — loaded dynamically from the DB.
        this.panelOpen = false;
        this.panelQuery = '';
        this.debounce = null;
        this.requestseq = 0; // Counts searches so late responses after a reset are ignored.
        this.initialresults = ''; // Start-state markup of the result list, restored by reset.
        this.ratings = {}; // {qid: {average, count, myrating}}.
    }

    BlockState.prototype.el = function(id) {
        return document.getElementById('qf-' + id + '-' + this.blockid);
    };
    BlockState.prototype.block = function() {
        return document.getElementById('qf-block-' + this.blockid);
    };

    // Init
    BlockState.prototype.init = function() {
        var self = this;

        // Load question types dynamically instead of hard-coding them.
        self.loadQuestionTypes();

        var resultsEl = self.el('results');
        self.initialresults = resultsEl ? resultsEl.innerHTML : '';

        // Difficulty chips.
        self.block().querySelectorAll('.qf-chip-diff').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var v = this.dataset.value;
                if (self.diffs[v]) {
                    delete self.diffs[v];
                    this.classList.remove('qf-chip-active');
                } else {
                    self.diffs[v] = true;
                    this.classList.add('qf-chip-active');
                }
                self.triggerSearch();
            });
        });

        // Combined search field.
        var searchEl = self.el('search');
        if (searchEl) {
            searchEl.addEventListener('input', function() {
                self.parseSearchInput(this.value);
            });
            searchEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    var val = this.value.trim();
                    if (val.charAt(0) === '#' && val.length > 1) {
                        self.addTag(val.substring(1));
                        this.value = '';
                        self.search = '';
                        self.triggerSearch();
                    }
                }
            });
        }

        // Dropdown toggle.
        var toggleBtn = self.el('cat-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                self.togglePanel();
            });
        }

        // The input in the dropdown header opens the panel.
        var filterInput = self.el('cat-filter');
        if (filterInput) {
            filterInput.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!self.panelOpen) {
                    self.openPanel();
                }
            });
        }

        // Search field in the panel.
        var panelSearch = self.el('cat-search');
        if (panelSearch) {
            panelSearch.addEventListener('input', function() {
                self.panelQuery = this.value.toLowerCase();
                self.renderPanelList();
            });
            panelSearch.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }

        // All / None.
        var wrap = self.el('catdrop-wrap');
        if (wrap) {
            var selAll = wrap.querySelector('.qf-cat-sel-all');
            var selNone = wrap.querySelector('.qf-cat-sel-none');
            if (selAll) {
                selAll.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.filteredCats().forEach(function(c) {
                        self.cats[c.id] = true;
                    });
                    self.renderPanelList();
                    self.updateCatInput();
                    self.triggerSearch();
                });
            }
            if (selNone) {
                selNone.addEventListener('click', function(e) {
                    e.stopPropagation();
                    self.filteredCats().forEach(function(c) {
                        delete self.cats[c.id];
                    });
                    self.renderPanelList();
                    self.updateCatInput();
                    self.triggerSearch();
                });
            }
        }

        // Clear the selection with the X button.
        var clearBtn = self.el('cat-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                self.cats = {};
                self.updateCatInput();
                self.renderPanelList();
                self.triggerSearch();
            });
        }

        // Close the panel on clicks outside it.
        document.addEventListener('click', function(e) {
            if (self.panelOpen) {
                var wrap = self.el('catdrop-wrap');
                if (wrap && !wrap.contains(e.target)) {
                    self.closePanel();
                }
            }
        });

        // Reset all filters and the result list to the start state.
        var resetBtn = self.block().querySelector('.qf-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                self.reset();
            });
        }

        // Select all results.
        var selAllResults = self.block().querySelector('.qf-select-all');
        if (selAllResults) {
            selAllResults.addEventListener('click', function() {
                self.toggleSelectAll();
            });
        }

        // Export
        self.block().querySelectorAll('.qf-export').forEach(function(btn) {
            btn.addEventListener('click', function() {
                self.doExport(this.dataset.format);
            });
        });
        var addQuiz = self.block().querySelector('.qf-add-to-quiz');
        if (addQuiz) {
            addQuiz.addEventListener('click', function() {
                self.addToQuiz();
            });
        }

        self.loadCategories();

        // Check capabilities via AJAX after pool login so export buttons
        // appear correctly without reloading the page.
        self.refreshCapabilities();

        self.initPoolLoginListener();
        self.restoreSelectionAfterReload();
    };

    /**
     * Registers the postMessage listener for pool login notifications.
     * The pool login tab sends 'qf_pool_login_done' after a successful login.
     */
    BlockState.prototype.initPoolLoginListener = function() {
        var self = this;
        var wwwroot = self.config.wwwroot || M.cfg.wwwroot;
        window.addEventListener('message', function(event) {
            if (event.origin !== new URL(wwwroot).origin) {
                return;
            }
            if (event.data && event.data.type === 'qf_pool_login_done') {
                // Pool login is now complete in the popup. The main page still
                // runs under the guest session and needs to adopt the new
                // session cookie. We reload so that Moodle picks up the pool
                // account's session — but only after saving the current filter
                // state so restoreSelectionAfterReload() can rebuild the view.
                //
                // If the state was already saved by openPreview() (guest path)
                // it is still there and the reload will restore it.
                // If for any reason it was not saved (e.g. a second preview
                // click before the first reload), save it now.
                try {
                    var restoreKey = 'qf_restore_selection_' + self.blockid;
                    if (!sessionStorage.getItem(restoreKey)) {
                        self.saveStateForReload();
                    }
                } catch (e) { /* Ignore storage errors. */ }
                window.location.reload();
            }
        });
    };

    /**
     * Restores selection and search state after a pool login reload.
     */
    BlockState.prototype.restoreSelectionAfterReload = function() {
        var self = this;
        try {
            var restoreKey = 'qf_restore_selection_' + self.blockid;
            var stored = sessionStorage.getItem(restoreKey);
            if (!stored) {
                return;
            }
            sessionStorage.removeItem(restoreKey);
            var snap = JSON.parse(stored);
            if (!snap) {
                return;
            }

            // Restore all filter state, not just selected IDs.
            self.search = snap.search || '';
            self.cats = snap.cats || {};
            self.types = snap.types || {};
            self.diffs = snap.diffs || {};
            self.tags = snap.tags || [];
            if (Array.isArray(snap.ids)) {
                snap.ids.forEach(function(id) {
                    self.selected[String(id)] = true;
                });
            }

            // Reflect search text back in the DOM.
            var searchEl = self.el('search');
            if (searchEl && self.search) {
                searchEl.value = self.search;
            }

            // Reflect active difficulty chips.
            self.block().querySelectorAll('.qf-chip-diff').forEach(function(btn) {
                if (self.diffs[btn.dataset.value]) {
                    btn.classList.add('qf-chip-active');
                }
            });

            // Re-render tag chips and trigger a new search.
            self.renderTagChips();
            self.updateCatInput();
            self.triggerSearch();
        } catch (e) { /* Ignore storage errors. */ }
    };

    /**
     * Fetches the current user's capabilities via AJAX and updates
     * the export buttons and isloggedin flag. Called during init.
     */
    BlockState.prototype.refreshCapabilities = function() {
        var self = this;
        Ajax.call([{
            methodname: 'block_fragenfilter_check_capabilities',
            args: {contextid: self.config.contextid || 1},
            done: function(result) {
                self.config.canexport = result.canexport;
                self.config.isloggedin = result.isloggedin;
                // Use the export formats resolved by the server.
                var fmtStr = result.exportformats;
                var formats = fmtStr.split(',').map(function(s) {
                    return s.trim();
                });
                // Show export buttons when the capability is granted.
                self.block().querySelectorAll('.qf-export').forEach(function(btn) {
                    var fmt = btn.dataset.format;
                    var allowed = result.canexport && formats.indexOf(fmt) !== -1;
                    btn.style.display = allowed ? '' : 'none';
                    btn.disabled = !allowed;
                });
                var addQuiz = self.block().querySelector('.qf-add-to-quiz');
                if (addQuiz) {
                    addQuiz.style.display = result.isloggedin ? '' : 'none';
                }
            },
            fail: function() {
                // Silently handle errors; buttons remain hidden.
            },
        }]);
    };

    // Dropdown panel.
    BlockState.prototype.openPanel = function() {
        var self = this;
        var panel = self.el('cat-panel');
        var icon = self.el('cat-toggle');
        if (!panel) {
            return;
        }
        panel.style.display = 'flex';
        self.panelOpen = true;
        if (icon) {
            icon.innerHTML = '<i class="fa fa-chevron-up" style="font-size:11px"></i>';
        }
        // Focus the search field.
        var ps = self.el('cat-search');
        if (ps) {
            setTimeout(function() {
                ps.focus();
            }, 50);
        }
    };

    BlockState.prototype.closePanel = function() {
        var self = this;
        var panel = self.el('cat-panel');
        var icon = self.el('cat-toggle');
        if (!panel) {
            return;
        }
        panel.style.display = 'none';
        self.panelOpen = false;
        if (icon) {
            icon.innerHTML = '<i class="fa fa-chevron-down" style="font-size:11px"></i>';
        }
    };

    BlockState.prototype.togglePanel = function() {
        if (this.panelOpen) {
            this.closePanel();
        } else {
            this.openPanel();
        }
    };

    // Load question types dynamically.
    BlockState.prototype.loadQuestionTypes = function() {
        var self = this;
        var wrap = self.el('type-chips');
        if (wrap) {
            wrap.innerHTML = '<span class="text-muted small">Typen werden geladen …</span>';
        }

        Ajax.call([{
            methodname: 'block_fragenfilter_get_questiontypes',
            args: {
                scope: self.config.searchscope || 'all',
                contextid: self.config.contextid || 1,
                source: self.config.qtypessource || 'installed',
            },
            done: function(result) {
                self.loadedQtypes = result.qtypes || [];
                self.renderTypeChips();
            },
            fail: function() {
                // Fallback: known types from QTYPE_FALLBACK.
                self.loadedQtypes = Object.keys(QTYPE_FALLBACK).map(function(k) {
                    return {key: k, label: QTYPE_FALLBACK[k]};
                });
                self.renderTypeChips();
            },
        }]);
    };

    // Categories.
    BlockState.prototype.loadCategories = function() {
        var self = this;
        var filterEl = self.el('cat-filter');
        Ajax.call([{
            methodname: 'block_fragenfilter_get_categories',
            args: {scope: self.config.searchscope || 'all', contextid: self.config.contextid || 1},
            done: function(result) {
                self.allCats = result.categories || [];
                if (filterEl) {
                    filterEl.placeholder = self.allCats.length + ' Sammlungen verfügbar …';
                    filterEl.readOnly = false;
                }
                self.updateCatInput();
                self.renderPanelList();
            },
            fail: function() {
                if (filterEl) {
                    filterEl.placeholder = 'Laden fehlgeschlagen';
                }
            },
        }]);
    };

    // Filtered list (by panelQuery).
    BlockState.prototype.filteredCats = function() {
        var q = this.panelQuery;
        if (!q) {
            return this.allCats;
        }
        return this.allCats.filter(function(c) {
            return c.name.toLowerCase().indexOf(q) !== -1
                || (c.contextlabel || '').toLowerCase().indexOf(q) !== -1;
        });
    };

    // Show selected question banks in the input field.
    BlockState.prototype.updateCatInput = function() {
        var self = this;
        var filterEl = self.el('cat-filter');
        var clearBtn = self.el('cat-clear');
        var label = self.el('cat-label');
        var n = Object.keys(self.cats).length;
        var total = self.allCats.length;

        if (filterEl) {
            if (n === 0) {
                filterEl.value = '';
                filterEl.placeholder = total + ' Sammlungen verfügbar …';
            } else if (n === 1) {
                var id = parseInt(Object.keys(self.cats)[0], 10);
                var cat = self.allCats.filter(function(c) {
                    return c.id === id;
                })[0];
                filterEl.value = cat ? cat.name : '1 gewählt';
            } else {
                filterEl.value = n + ' Sammlungen gewählt';
            }
        }
        if (clearBtn) {
            clearBtn.style.display = n > 0 ? '' : 'none';
        }
        if (label) {
            label.textContent = 'FRAGESAMMLUNGEN'
                + (n > 0 ? ' (' + n + ' aktiv)' : '')
                + ' — ' + total + ' GESAMT';
        }
    };

    // Render the dropdown list.
    BlockState.prototype.renderPanelList = function() {
        var self = this;
        var list = self.el('cat-list');
        if (!list) {
            return;
        }

        var cats = self.filteredCats();

        if (!cats.length) {
            list.innerHTML = '<div class="text-muted small px-3 py-2">Keine Treffer.</div>';
            return;
        }

        // Group by contextlabel.
        var groups = {};
        var order = [];
        cats.forEach(function(c) {
            var lbl = c.contextlabel || 'Allgemein';
            if (!groups[lbl]) {
                groups[lbl] = [];
                order.push(lbl);
            }
            groups[lbl].push(c);
        });

        var html = '';
        order.forEach(function(lbl) {
            // Group header.
            html += '<div style="padding:4px 10px 2px;font-size:10px;color:#6c757d;'
                  + 'font-weight:600;text-transform:uppercase;letter-spacing:.04em;'
                  + 'border-top:1px solid #f0f0f0;margin-top:2px">'
                  + escHtml(lbl) + '</div>';
            groups[lbl].forEach(function(c) {
                var checked = self.cats[c.id] ? ' checked' : '';
                html += '<label style="display:flex;align-items:center;gap:8px;'
                      + 'padding:4px 12px;cursor:pointer;font-size:12px;'
                      + 'color:var(--bs-body-color,#212529)" '
                      + 'class="qf-cat-row' + (self.cats[c.id] ? ' qf-cat-row-active' : '') + '">'
                      + '<input type="checkbox" class="form-check-input qf-cat-chk" '
                      + 'style="margin:0;flex-shrink:0" data-catid="' + c.id + '"' + checked + '>'
                      + '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;'
                      + 'white-space:nowrap" title="' + escHtml(c.name) + '">'
                      + escHtml(c.name) + '</span>'
                      + '<span style="color:#adb5bd;font-size:10px;flex-shrink:0">'
                      + c.questioncount + '</span>'
                      + '</label>';
            });
        });

        list.innerHTML = html;

        // Checkbox handler.
        list.querySelectorAll('.qf-cat-chk').forEach(function(chk) {
            chk.addEventListener('change', function(e) {
                e.stopPropagation();
                var id = parseInt(this.dataset.catid, 10);
                if (this.checked) {
                    self.cats[id] = true;
                } else {
                    delete self.cats[id];
                }
                // Highlight the row.
                var row = this.closest('.qf-cat-row');
                if (row) {
                    row.classList.toggle('qf-cat-row-active', !!this.checked);
                }
                self.updateCatInput();
                self.triggerSearch();
            });
        });
    };

    // Parse the search field.
    BlockState.prototype.parseSearchInput = function(val) {
        var self = this;
        var plain = val.trim();
        var hint = self.el('search-hint');
        if (hint) {
            if (plain.charAt(0) === '#' && plain.length > 1) {
                hint.textContent = 'Enter: "' + plain.substring(1) + '" als Tag-Filter';
                hint.style.display = '';
            } else {
                hint.style.display = 'none';
            }
        }
        self.search = (plain.charAt(0) === '#') ? '' : plain;
        self.triggerSearch();
    };

    // Question type chips.
    BlockState.prototype.renderTypeChips = function() {
        var self = this;
        var wrap = self.el('type-chips');
        if (!wrap) {
            return;
        }
        if (!self.loadedQtypes.length) {
            wrap.innerHTML = '<span class="text-muted small">Keine Fragetypen gefunden.</span>';
            return;
        }
        var html = '';
        self.loadedQtypes.forEach(function(t) {
            var active = self.types[t.key] ? ' qf-chip-active' : '';
            html += '<button class="badge qf-chip qf-chip-type' + active + '" data-type="' + t.key + '">'
                  + escHtml(t.label) + '</button> ';
        });
        wrap.innerHTML = html;
        wrap.querySelectorAll('.qf-chip-type').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var t = this.dataset.type;
                if (self.types[t]) {
                    delete self.types[t];
                    this.classList.remove('qf-chip-active');
                } else {
                    self.types[t] = true;
                    this.classList.add('qf-chip-active');
                }
                self.triggerSearch();
            });
        });
    };

    // Tags
    BlockState.prototype.addTag = function(val) {
        if (val && this.tags.indexOf(val) === -1) {
            this.tags.push(val);
            this.renderTagChips();
            this.triggerSearch();
        }
    };
    BlockState.prototype.removeTag = function(val) {
        this.tags = this.tags.filter(function(t) {
            return t !== val;
        });
        this.renderTagChips();
        this.triggerSearch();
    };
    BlockState.prototype.renderTagChips = function() {
        var self = this;
        var wrap = self.el('tag-chips');
        if (!wrap) {
            return;
        }
        if (!self.tags.length) {
            wrap.innerHTML = '';
            return;
        }
        wrap.innerHTML = self.tags.map(function(t) {
            return '<span class="badge qf-chip qf-chip-tag qf-chip-active">#' + escHtml(t)
                 + ' <button class="qf-rm-tag" data-tag="' + escHtml(t)
                 + '" style="border:none;background:none;color:inherit;cursor:pointer;padding:0 0 0 2px">'
                 + '&times;</button></span> ';
        }).join('');
        wrap.querySelectorAll('.qf-rm-tag').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                self.removeTag(this.dataset.tag);
            });
        });
    };

    /**
     * Clears search text, tags, collections, types, difficulty levels, selection
     * and results, and drops any pending or running search.
     */
    BlockState.prototype.reset = function() {
        var self = this;
        clearTimeout(self.debounce);
        self.requestseq++;
        self.search = '';
        self.cats = {};
        self.types = {};
        self.diffs = {};
        self.tags = [];
        self.selected = {};
        self.results = [];
        self.ratings = {};
        self.panelQuery = '';

        var searchEl = self.el('search');
        if (searchEl) {
            searchEl.value = '';
        }
        var hint = self.el('search-hint');
        if (hint) {
            hint.style.display = 'none';
        }
        var panelSearch = self.el('cat-search');
        if (panelSearch) {
            panelSearch.value = '';
        }
        self.block().querySelectorAll('.qf-chip-diff').forEach(function(btn) {
            btn.classList.remove('qf-chip-active');
        });
        var spinner = self.el('spinner');
        if (spinner) {
            spinner.classList.add('d-none');
        }
        self.renderTagChips();
        self.renderTypeChips();
        self.renderPanelList();
        self.updateCatInput();

        var list = self.el('results');
        if (list) {
            list.innerHTML = self.initialresults;
        }
        var count = self.el('count');
        if (count) {
            count.textContent = '–';
        }
        self.updateFooter();
    };

    // Search.
    BlockState.prototype.triggerSearch = function() {
        var self = this;
        clearTimeout(self.debounce);
        self.debounce = setTimeout(function() {
            self.runSearch();
        }, 350);
    };

    BlockState.prototype.runSearch = function() {
        var self = this;
        var seq = ++self.requestseq;
        var spinner = self.el('spinner');
        if (spinner) {
            spinner.classList.remove('d-none');
        }

        Ajax.call([{
            methodname: 'block_fragenfilter_search_questions',
            args: {
                search: self.search,
                categories: Object.keys(self.cats).join(','),
                types: Object.keys(self.types).join(','),
                difficulty: '',
                tags: Object.keys(self.diffs).concat(self.tags).join(','),
                scope: self.config.searchscope || 'all',
                contextid: self.config.contextid || 1,
                limit: 200,
            },
            done: function(result) {
                if (seq !== self.requestseq) {
                    return;
                }
                if (spinner) {
                    spinner.classList.add('d-none');
                }
                self.results = result.questions || [];
                var valid = {};
                self.results.forEach(function(q) {
                    valid[q.id] = true;
                });
                Object.keys(self.selected).forEach(function(id) {
                    if (!valid[id]) {
                        delete self.selected[id];
                    }
                });
                self.renderResults();
                self.updateFooter();
                // Load ratings.
                if (self.results.length) {
                    self.loadRatings(self.results.map(function(q) {
                        return q.id;
                    }));
                }
            },
            fail: function(err) {
                if (seq !== self.requestseq) {
                    return;
                }
                if (spinner) {
                    spinner.classList.add('d-none');
                }
                var list = self.el('results');
                if (list) {
                    list.innerHTML = '<div class="alert alert-warning small p-2">'
                        + (err.message || 'Suche fehlgeschlagen') + '</div>';
                }
            },
        }]);
    };

    // Result list.
    BlockState.prototype.renderResults = function() {
        var self = this;
        var wrap = self.el('results');
        var count = self.el('count');
        if (!wrap) {
            return;
        }
        if (count) {
            count.textContent = self.results.length
                + ' Frage' + (self.results.length !== 1 ? 'n' : '') + ' gefunden';
        }
        if (!self.results.length) {
            wrap.innerHTML = '<div class="text-center text-muted small py-3">Keine Treffer.</div>';
            return;
        }
        var html = '';
        self.results.forEach(function(q) {
            var sel = !!self.selected[q.id];
            var typeLbl = QTYPE_FALLBACK[q.qtype] || q.qtype;
            self.loadedQtypes.forEach(function(t) {
                if (t.key === q.qtype) {
                    typeLbl = t.label;
                }
            });
            var typeCol = TYPE_COLOR[q.qtype] || 'bg-secondary bg-opacity-10 text-secondary';
            var tagPills = (q.tags || []).map(function(t) {
                return '<span class="badge bg-primary bg-opacity-10 text-primary" style="font-size:10px">#'
                     + escHtml(t.name) + '</span>';
            }).join(' ');
            var stars = self.renderStars(q.id);

            html += '<div class="qf-item p-1 mb-1 rounded'
                 + (sel ? ' qf-item-sel bg-info bg-opacity-10 border border-info' : '')
                 + '" data-qid="' + q.id + '">'
                 + '<div class="d-flex gap-2 align-items-start">'
                 + '<input type="checkbox" class="form-check-input mt-1 flex-shrink-0 qf-chk"'
                 + (sel ? ' checked' : '') + ' data-qid="' + q.id + '">'
                 + '<div style="min-width:0;flex:1">'
                 // Title row with preview button, which is always visible.
                 + '<div class="d-flex align-items-center gap-1 mb-1">'
                 + '<span class="small fw-semibold text-truncate" style="flex:1" '
                 + 'title="' + escHtml(q.name) + '">' + escHtml(q.name) + '</span>'
                 + '<button class="btn btn-sm qf-preview-btn flex-shrink-0" '
                 + 'data-qid="' + q.id + '" '
                 + 'title="Vorschau anzeigen" '
                 + 'style="font-size:11px;color:#fff;background:#6b7280;border:none;'
                 + 'border-radius:4px;padding:1px 7px;line-height:1.6">'
                 + '<i class="fa fa-eye"></i> Vorschau</button>'
                 + '</div>'
                 // Type badges.
                 + '<div class="d-flex flex-wrap gap-1 mb-1">'
                 + '<span class="badge ' + typeCol + '" style="font-size:10px">' + typeLbl + '</span>'
                 + '<span class="badge bg-light text-muted" style="font-size:10px">'
                 + escHtml(q.categoryname) + '</span>'
                 + tagPills + '</div>'
                 // Star rating.
                 + '<div>' + stars + '</div>'
                 + '</div></div></div>';
        });
        wrap.innerHTML = html;

        // Clicking a row toggles its checkbox unless the preview or a star was clicked.
        wrap.querySelectorAll('.qf-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                if (e.target.tagName === 'INPUT'
                    || e.target.closest('.qf-preview-btn')
                    || e.target.closest('.qf-stars')) {
                    return;
                }
                self.toggleSelect(parseInt(this.dataset.qid, 10));
            });
        });
        wrap.querySelectorAll('.qf-chk').forEach(function(chk) {
            chk.addEventListener('change', function() {
                self.toggleSelect(parseInt(this.dataset.qid, 10));
            });
        });

        // Clicking the preview button opens the modal.
        wrap.querySelectorAll('.qf-preview-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                self.openPreview(parseInt(this.dataset.qid, 10));
            });
        });

        // Star hover and click.
        wrap.querySelectorAll('.qf-star').forEach(function(star) {
            star.addEventListener('mouseenter', function() {
                var n = parseInt(this.dataset.star, 10);
                var container = this.closest('.qf-stars');
                if (container) {
                    container.querySelectorAll('.qf-star').forEach(function(s) {
                        s.style.color = parseInt(s.dataset.star, 10) <= n ? '#f59e0b' : '#e5e7eb';
                    });
                }
            });
            star.addEventListener('mouseleave', function() {
                var qid = parseInt(this.dataset.qid, 10);
                var r = self.ratings[qid] || {average: 0, myrating: 0};
                var ref = r.myrating > 0 ? r.myrating : Math.round(r.average);
                var container = this.closest('.qf-stars');
                if (container) {
                    container.querySelectorAll('.qf-star').forEach(function(s) {
                        var i = parseInt(s.dataset.star, 10);
                        var activeColor = r.myrating > 0 ? '#f59e0b' : '#9ca3af';
                        s.style.color = i <= ref ? activeColor : '#e5e7eb';
                    });
                }
            });
            star.addEventListener('click', function(e) {
                e.stopPropagation();
                self.rateQuestion(parseInt(this.dataset.qid, 10), parseInt(this.dataset.star, 10));
            });
        });
    };

    BlockState.prototype.toggleSelect = function(id) {
        if (this.selected[id]) {
            delete this.selected[id];
        } else {
            this.selected[id] = true;
        }
        this.renderResults();
        this.updateFooter();
    };
    BlockState.prototype.toggleSelectAll = function() {
        var self = this;
        if (Object.keys(self.selected).length === self.results.length) {
            self.selected = {};
        } else {
            self.results.forEach(function(q) {
                self.selected[q.id] = true;
            });
        }
        self.renderResults();
        self.updateFooter();
    };
    BlockState.prototype.updateFooter = function() {
        var n = Object.keys(this.selected).length;
        var info = this.el('sel-info');
        if (info) {
            info.textContent = n === 0
                ? 'Nichts ausgewählt'
                : n + ' Frage' + (n !== 1 ? 'n' : '') + ' ausgewählt';
        }
        this.block().querySelectorAll('.qf-export,.qf-add-to-quiz').forEach(function(b) {
            b.disabled = (n === 0);
        });
    };

    // Export / Add to quiz.
    BlockState.prototype.doExport = function(format) {
        var self = this;
        var ids = Object.keys(self.selected).join(',');
        if (!ids) {
            return;
        }
        Ajax.call([{
            methodname: 'block_fragenfilter_export_questions',
            args: {questionids: ids, format: format, contextid: self.config.contextid || 1},
            done: function(result) {
                if (result.success && result.url) {
                    var a = document.createElement('a');
                    a.href = result.url;
                    a.download = result.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                }
            },
            fail: Notification.exception,
        }]);
    };
    // AddToQuiz is disabled for now because pool accounts cannot edit quizzes.
    // Re-enable once a specific quiz context (course + quiz ID) is known.
    BlockState.prototype.addToQuiz = function() { /* Noop — reserved for future use. */ };

    // ---------------------------------------------------------------
    // Preview modal.
    // ---------------------------------------------------------------

    /**
     * Opens the question preview.
     *
     * Logged-in users: open the Moodle preview page directly in a new tab.
     * Guests: redirect through guest_preview.php (logs in a pool account),
     *                 also in a new tab.
     *
     * @param {Number} qid Question ID.
     */
    BlockState.prototype.openPreview = function(qid) {
        var self = this;
        var wwwroot = self.config.wwwroot || M.cfg.wwwroot;

        // All users — including already-logged-in pool accounts — go through
        // guest_preview.php so that it can look up the cmid of the mod_qbank
        // activity that holds the question (required by Moodle 5+ preview.php)
        // and redirect accordingly.  Guests additionally get a pool account here.
        if (!self.config.isloggedin) {
            // Guest: the pool login may cause Moodle to refresh the main page
            // (session-cookie change). Save filter state so it can be restored.
            self.saveStateForReload();
        }

        // POST to guest_preview.php (sesskey required for CSRF protection).
        var target = 'qf_preview_' + qid;
        window.open('', target, 'width=900,height=700,scrollbars=yes,resizable=yes');
        var form = document.createElement('form');
        form.method = 'post';
        form.action = wwwroot + '/blocks/fragenfilter/guest_preview.php';
        form.target = target;
        [['qid', qid], ['sesskey', M.cfg.sesskey]].forEach(function(field) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = field[0];
            input.value = field[1];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        form.remove();
    };

    /**
     * Saves the current filter state to sessionStorage so it can be restored
     * after an unexpected page reload (e.g. triggered by a session-cookie change
     * when the pool login happens in the preview popup).
     */
    BlockState.prototype.saveStateForReload = function() {
        var self = this;
        try {
            var key = 'qf_restore_selection_' + self.blockid;
            var snap = {
                search: self.search,
                cats: self.cats,
                types: self.types,
                diffs: self.diffs,
                tags: self.tags,
                ids: Object.keys(self.selected).map(Number),
            };
            sessionStorage.setItem(key, JSON.stringify(snap));
        } catch (e) { /* Ignore storage errors. */ }
    };

    // ---------------------------------------------------------------
    // Star rating.
    // ---------------------------------------------------------------

    /**
     * Loads ratings for all visible questions.
     *
     * @param {Array} qids List of question IDs.
     */
    BlockState.prototype.loadRatings = function(qids) {
        var self = this;
        if (!qids.length) {
            return;
        }
        Ajax.call([{
            methodname: 'block_fragenfilter_get_ratings',
            args: {
                questionids: qids.join(','),
                contextid: self.config.contextid || 1,
            },
            done: function(result) {
                (result.ratings || []).forEach(function(r) {
                    self.ratings[r.questionid] = r;
                });
                self.renderResults();
            },
            fail: function() { /* Bewertungen sind optional */ },
        }]);
    };

    /**
     * Saves a question's star rating.
     *
     * @param {Number} qid   Question ID.
     * @param {Number} stars Number of stars (1-5).
     */
    BlockState.prototype.rateQuestion = function(qid, stars) {
        var self = this;
        Ajax.call([{
            methodname: 'block_fragenfilter_rate_question',
            args: {
                questionid:  qid,
                rating:      stars,
                contextid: self.config.contextid || 1,
            },
            done: function(result) {
                self.ratings[qid] = result;
                self.renderResults();
            },
            fail: function() {
                // Silent error handling.
            },
        }]);
    };

    /**
     * Returns the HTML for a question's star rating.
     *
     * Own rating: yellow stars.
     * Average only: grey stars.
     *
     * @param {Number} qid Question ID.
     * @return {String} HTML string.
     */
    BlockState.prototype.renderStars = function(qid) {
        var r = this.ratings[qid] || {average: 0, count: 0, myrating: 0};
        var avg = r.average || 0;
        var mine = r.myrating || 0;
        var count = r.count || 0;
        var stars = '';

        for (var i = 1; i <= 5; i++) {
            var colorFilled = mine > 0 ? '#f59e0b' : '#9ca3af';
            var colorEmpty = '#e5e7eb';
            var isFilled = mine > 0 ? i <= mine : i <= Math.round(avg);
            var color = isFilled ? colorFilled : colorEmpty;
            stars += '<span class="qf-star" data-qid="' + qid + '" data-star="' + i + '" '
                   + 'style="cursor:pointer;font-size:16px;color:' + color + ';transition:color .1s" '
                   + 'title="' + i + ' Stern' + (i > 1 ? 'e' : '') + '">&#9733;</span>';
        }

        var avgText = count > 0
            ? '<span style="font-size:10px;color:#6b7280;margin-left:4px">'
              + avg.toFixed(1) + ' (' + count + ')</span>'
            : '<span style="font-size:10px;color:#9ca3af;margin-left:4px">Noch keine Bewertung</span>';

        return '<span class="qf-stars" data-qid="' + qid + '">' + stars + avgText + '</span>';
    };

    /**
     * Escapes HTML special characters for safe output.
     *
     * @param {String} s Text to escape.
     * @return {String} Escaped text.
     */
    function escHtml(s) {
        if (s === null || s === undefined) {
            return '';
        }
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return {
        /**
         * Initialises a block instance.
         *
         * @param {Object} config Configuration from PHP.
         */
        init: function(config) {
            var state = new BlockState(config.blockid, config);
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    state.init();
                });
            } else {
                state.init();
            }
        },
    };
});
