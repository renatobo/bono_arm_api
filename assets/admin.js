function bonoArmApiCopy(elementId) {
    const source = document.getElementById(elementId);

    if (!source) {
        return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(source.textContent);
        return;
    }

    const temp = document.createElement('textarea');
    temp.value = source.textContent;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand('copy');
    document.body.removeChild(temp);
}

document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.bono-arm-api-tab');
    const panels = document.querySelectorAll('.bono-arm-api-panel');
    const copyButtons = document.querySelectorAll('.bono-arm-api-copy');

    copyButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            bonoArmApiCopy(button.getAttribute('data-copy-target'));
        });
    });

    function activateTab(targetPanel, updateHash) {
        let hasMatch = false;

        tabs.forEach(function (item) {
            const isTarget = item.getAttribute('data-panel') === targetPanel;
            item.classList.toggle('nav-tab-active', isTarget);
            item.setAttribute('aria-selected', isTarget ? 'true' : 'false');
            hasMatch = hasMatch || isTarget;
        });

        panels.forEach(function (panel) {
            const isTarget = panel.getAttribute('data-panel') === targetPanel;
            panel.classList.toggle('is-active', isTarget);
            panel.hidden = !isTarget;
        });

        if (hasMatch && updateHash) {
            window.location.hash = targetPanel;
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            activateTab(tab.getAttribute('data-panel'), true);
        });
    });

    const initialPanel = window.location.hash ? window.location.hash.replace('#', '') : 'api';
    activateTab(initialPanel, false);

    window.addEventListener('hashchange', function () {
        const hashPanel = window.location.hash ? window.location.hash.replace('#', '') : 'api';
        activateTab(hashPanel, false);
    });
});
