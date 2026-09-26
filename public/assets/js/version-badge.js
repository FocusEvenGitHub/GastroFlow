(function () {
    fetch('/version')
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data || !data.version) return;
            var el = document.createElement('div');
            el.textContent = data.version;
            el.style.cssText = 'position:fixed;bottom:4px;right:6px;font-size:11px;'
                + 'color:#6c757d;opacity:.55;z-index:1050;pointer-events:none;'
                + 'font-family:monospace;';
            document.body.appendChild(el);
        })
        .catch(function () { /* cosmetic only — must never break the page */ });
})();
