(function () {
    var match = /^#t=([^&]+)$/.exec(window.location.hash);
    var form = document.getElementById('sso');
    if (!match) {
        form.hidden = true;
        document.getElementById('sso-missing').hidden = false;
        return;
    }
    form.elements.t.value = decodeURIComponent(match[1]);
    window.history.replaceState(null, '', window.location.pathname);
    form.submit();
})();
