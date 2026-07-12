<?php

/**
 * Makes clicking a table name open its data (select) instead of the structure
 * page. Rewrites the table-name links in the sidebar and the database
 * overview (both carry title="Show structure"); the select page's own
 * "Show structure" link has no title attribute and is left alone, so the
 * structure view stays one click away. Local-dev convenience only — mounted
 * into the adminer container's plugins-enabled/ directory.
 */
final class SelectByDefaultPlugin extends \Adminer\Plugin
{
    public function head($dark = null)
    {
        echo \Adminer\script(<<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('a[title="Show structure"]').forEach(function (a) {
        a.href = a.getAttribute('href').replace(/([?&])table=/, '$1select=');
        a.title = 'Select data';
    });
});
JS);
    }
}

return new SelectByDefaultPlugin;
