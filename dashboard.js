/**
 * CSWDO Inventory System - Frontend Core Script
 * Developed by: John Marvin Vicente
 */

// --- 1. CORE WORKSPACE TAB NAVIGATION SWITCHER ---
function switchWorkspaceTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.view-panel').forEach(panel => panel.classList.remove('active'));
    
    const targetTab = document.getElementById('tab-' + tabId);
    const targetView = document.getElementById('view-' + tabId);
    
    if (targetTab) targetTab.classList.add('active');
    if (targetView) targetView.classList.add('active');
}

// --- 2. DYNAMIC INPUT DATE FILTER TYPE TOGGLE HANDLERS ---
function handleFilterScopeChange(tabType) {
    var mode = document.getElementById(tabType + '_filter_mode').value;
    document.getElementById(tabType + '_container_day').style.display = (mode === 'day') ? 'inline-block' : 'none';
    document.getElementById(tabType + '_container_month').style.display = (mode === 'month') ? 'inline-block' : 'none';
    document.getElementById(tabType + '_container_year').style.display = (mode === 'year') ? 'inline-block' : 'none';
    document.getElementById(tabType + '_container_range').style.display = (mode === 'range') ? 'inline-block' : 'none';
}

// --- 3. ONSCREEN FILTER FUNCTION FOR HISTORY DATA ROWS ---
function applyHistoryTableFilter() {
    var mode = document.getElementById('history_filter_mode').value;
    var targetValue = "";
    var rangeStart = "";
    var rangeEnd = "";
    
    if (mode === 'day') {
        targetValue = document.getElementById('history_input_day').value;
    } else if (mode === 'month') {
        targetValue = document.getElementById('history_input_month').value;
    } else if (mode === 'year') {
        targetValue = document.getElementById('history_input_year').value;
    } else if (mode === 'range') {
        rangeStart = document.getElementById('history_input_start').value;
        rangeEnd = document.getElementById('history_input_end').value;
        if (!rangeStart || !rangeEnd) {
            alert("Please pick both a Start Date and End Date.");
            return;
        }
    }

    var rows = document.querySelectorAll(".history-row-item");
    
    rows.forEach(function(row) {
        if (mode === 'all') {
            row.style.display = "";
            return;
        }
        
        var dateCell = row.querySelector(".history-date-cell");
        var dateText = dateCell ? (dateCell.innerText || dateCell.textContent).trim() : "";
        var cleanDateISO = dateText.substring(0, 10);

        var match = false;
        if (mode === 'range') {
            if (cleanDateISO >= rangeStart && cleanDateISO <= rangeEnd) {
                match = true;
            }
        } else {
            if (dateText.includes(targetValue)) {
                match = true;
            }
        }
        
        row.style.display = match ? "" : "none";
    });
}

// --- 4. EXPORT ENGINE FOR CURRENTLY DISPLAYED LOGS ONLY ---
function exportFilteredHistoryOnly() {
    var csv = [];
    var table = document.getElementById("history-table");
    if (!table) return;

    var rows = table.querySelectorAll("tr");
    
    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        if (i === 0 || row.style.display !== "none") {
            var rowData = [];
            var cols = row.querySelectorAll("th, td");
            
            for (var j = 0; j < cols.length; j++) {
                var cleanText = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/\s+/g, ' ').trim();
                cleanText = cleanText.replace(/"/g, '""');
                rowData.push('"' + cleanText + '"');
            }
            csv.push(rowData.join(","));
        }
    }

    if (csv.length <= 1) {
        alert("No visible rows to export.");
        return;
    }

    var mode = document.getElementById('history_filter_mode').value;
    var filename = "History_Report_" + mode + ".csv";
    
    var csvBlob = new Blob([csv.join("\n")], { type: "text/csv;charset=utf-8;" });
    var linkElement = document.createElement("a");
    linkElement.href = window.URL.createObjectURL(csvBlob);
    linkElement.download = filename;
    linkElement.style.display = "none";
    document.body.appendChild(linkElement);
    linkElement.click();
    document.body.removeChild(linkElement);
}

// --- 5. SYSTEM AUTOCOMPLETE & SELECTION ENGINES ---
function searchAutocompleteEngine(panelType) {
    const inputId = panelType === 'IN' ? 'search_autocomplete_in' : 'search_autocomplete_out';
    const boxId = panelType === 'IN' ? 'suggestions_box_in' : 'suggestions_box_out';
    
    const query = document.getElementById(inputId).value.toLowerCase().trim();
    const box = document.getElementById(boxId);
    
    box.innerHTML = '';
    
    if (panelType === 'IN') {
        if (typeof rawProductsIn === 'undefined') return;
        const matches = rawProductsIn.filter(p => p.name.toLowerCase().includes(query) || p.cat.toLowerCase().includes(query));
        
        if (matches.length === 0) {
            box.innerHTML = '<div class="suggestion-item" style="color:#888; cursor:default;">No matches found. Create new item profile below.</div>';
        } else {
            matches.forEach(item => {
                const div = document.createElement('div');
                div.className = 'suggestion-item';
                div.innerHTML = `${item.name} <span class="meta-tag">${item.cat}</span>`;
                // Use mousedown instead of click to prevent premature blur event behaviors
                div.onmousedown = (e) => {
                    e.preventDefault();
                    selectItemIn(item.id, item.name, item.cat);
                };
                box.appendChild(div);
            });
        }
    } else {
        if (typeof rawBatchesOut === 'undefined') return;
        const matches = rawBatchesOut.filter(b => b.name.toLowerCase().includes(query) || b.expiry.toLowerCase().includes(query));
        
        if (matches.length === 0) {
            box.innerHTML = '<div class="suggestion-item" style="color:#888; cursor:default;">No matching inventory stock batches.</div>';
        } else {
            matches.forEach(batch => {
                const div = document.createElement('div');
                div.className = 'suggestion-item';
                div.innerHTML = `<strong>${batch.name}</strong> [${batch.expiry}] <span class="meta-tag" style="background:#fff3cd; color:#856404;">${batch.qty} pcs left</span>`;
                div.onmousedown = (e) => {
                    e.preventDefault();
                    selectBatchOut(batch.composite, `${batch.name} [${batch.expiry}] (${batch.qty} pcs available)`);
                };
                box.appendChild(div);
            });
        }
    }
    box.style.display = 'block';
}

function selectItemIn(id, name, cat) {
    document.getElementById('hidden_product_id_in').value = id;
    document.getElementById('search_autocomplete_in').value = `${name} [${cat}]`;
    document.getElementById('suggestions_box_in').style.display = 'none';
    document.getElementById('search_autocomplete_in').style.borderColor = '#ccd0d5';
    
    const expWrapper = document.getElementById('movement_expiry_wrapper');
    const expiryInput = document.getElementById('movement_expiry_date');
    
    if (cat === 'Food') {
        expWrapper.style.display = 'block';
        expiryInput.setAttribute('required', 'required');
    } else {
        expWrapper.style.display = 'none';
        expiryInput.removeAttribute('required');
        expiryInput.value = '';
    }
}

function selectBatchOut(compositeValue, displayLabel) {
    document.getElementById('hidden_batch_composite_out').value = compositeValue;
    document.getElementById('search_autocomplete_out').value = displayLabel;
    document.getElementById('suggestions_box_out').style.display = 'none';
    document.getElementById('search_autocomplete_out').style.borderColor = '#ccd0d5';
}

// --- 6. SYSTEM STOCKS MATRIX ON-SCREEN VISIBILITY FILTERS ---
function filterStocksTableByCategory() {
    const selectedValue = document.getElementById("stock_category_filter").value;
    const rows = document.querySelectorAll(".stock-row-item");
    rows.forEach(row => {
        const itemCategory = row.getAttribute("data-category");
        if (selectedValue === "ALL" || itemCategory === selectedValue) {
            row.style.display = "";
        } else {
            row.style.display = "none";
        }
    });
}

// --- 7. PACKAGING INTERFACE INTERACTIVE SCALE MULTIPLIERS ---
function toggleInMultiplier(val) {
    const wrapper = document.getElementById('multiplier_in_wrapper');
    const input = document.getElementById('unit_multiplier_in');
    const cleanVal = val.trim().toLowerCase();
    if (cleanVal === 'piece' || cleanVal === 'pcs' || cleanVal === '') {
        wrapper.style.display = 'none';
        input.value = '1';
    } else {
        wrapper.style.display = 'block';
    }
}

function toggleOutMultiplier(val) {
    const wrapper = document.getElementById('multiplier_out_wrapper');
    const input = document.getElementById('unit_multiplier_out');
    const cleanVal = val.trim().toLowerCase();
    if (cleanVal === 'piece' || cleanVal === 'pcs' || cleanVal === '') {
        wrapper.style.display = 'none';
        input.value = '1';
    } else {
        wrapper.style.display = 'block';
    }
}

// --- 8. GLOBAL CSV DATA REPORT GENERATION FOR CURRENT STOCKS ---
function exportCurrentStocksTable() {
    var csv = [];
    var table = document.getElementById("stocks-table");
    if (!table) return;
    var rows = table.querySelectorAll("tr");
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].style.display === "none") continue;
        var rowData = [];
        var cols = rows[i].querySelectorAll("th, td");
        for (var j = 0; j < cols.length; j++) {
            var text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/\s+/g, ' ').trim();
            text = text.replace(/"/g, '""');
            rowData.push('"' + text + '"');
        }
        csv.push(rowData.join(","));
    }
    var csvBlob = new Blob([csv.join("\n")], { type: "text/csv;charset=utf-8;" });
    var downloadLink = document.createElement("a");
    downloadLink.href = window.URL.createObjectURL(csvBlob);
    downloadLink.download = "Current_Inventory_Stocks_Report.csv";
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// --- 9. DEFENSIVE SUBMIT EVENT INTERCEPTORS & FALLBACK HOOKS ---
document.addEventListener("DOMContentLoaded", function() {
    // Initialize active tab based on PHP hint (default to 'in')
    const activeTabHint = document.body.getAttribute('data-active-tab') || 'in';
    switchWorkspaceTab(activeTabHint);
    
    // Intercept form submissions inside IN flow panel
    const searchInEl = document.getElementById('search_autocomplete_in');
    if (searchInEl) {
        const formIn = searchInEl.closest('form');
        
        searchInEl.addEventListener('blur', function() {
            setTimeout(() => { document.getElementById('suggestions_box_in').style.display = 'none'; }, 200);
        });

        formIn.addEventListener('submit', function(e) {
            const payload = document.getElementById('hidden_product_id_in').value;
            if (!payload) {
                e.preventDefault();
                alert("Please click a valid item from the suggested autocomplete dropdown list before submitting.");
                searchInEl.focus();
                searchInEl.style.borderColor = "#cc0000";
            }
        });

        searchInEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const box = document.getElementById('suggestions_box_in');
                if (box.style.display === 'block') {
                    const firstItem = box.querySelector('.suggestion-item');
                    if (firstItem && !firstItem.innerText.includes('No matches')) {
                        e.preventDefault();
                        firstItem.dispatchEvent(new Event('mousedown'));
                    }
                }
            }
        });
    }

    // Intercept form submissions inside OUT flow panel
    const searchOutEl = document.getElementById('search_autocomplete_out');
    if (searchOutEl) {
        const formOut = searchOutEl.closest('form');

        searchOutEl.addEventListener('blur', function() {
            setTimeout(() => { document.getElementById('suggestions_box_out').style.display = 'none'; }, 200);
        });

        formOut.addEventListener('submit', function(e) {
            const payload = document.getElementById('hidden_batch_composite_out').value;
            if (!payload) {
                e.preventDefault();
                alert("Please click an active stock batch from the dropdown suggestions list before releasing stock.");
                searchOutEl.focus();
                searchOutEl.style.borderColor = "#cc0000";
            }
        });

        searchOutEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const box = document.getElementById('suggestions_box_out');
                if (box.style.display === 'block') {
                    const firstItem = box.querySelector('.suggestion-item');
                    if (firstItem && !firstItem.innerText.includes('No matching')) {
                        e.preventDefault();
                        firstItem.dispatchEvent(new Event('mousedown'));
                    }
                }
            }
        });
    }
});