<?php

/**
 * Makes clicking a table name open its data (select) instead of the structure
 * page. Rewrites the table-name links in the sidebar (class="structure") and
 * the database overview (id="Table-<name>") — locale-independent hooks, since
 * the title attribute is translated. The select page's own "Show structure"
 * link has neither and is left alone, so the structure view stays one click
 * away. Local-dev convenience only — mounted into the adminer container's
 * plugins-enabled/ directory.
 */
final class SelectByDefaultPlugin extends \Adminer\Plugin
{
    public function head($dark = null)
    {
        echo \Adminer\script(<<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#tables a.structure, a[id^="Table-"]').forEach(function (a) {
        a.href = a.getAttribute('href').replace(/([?&])table=/, '$1select=');
        a.removeAttribute('title');
    });
});
JS);
    }
}

return new SelectByDefaultPlugin;
