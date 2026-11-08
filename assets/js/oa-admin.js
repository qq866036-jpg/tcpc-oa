/**
 * tcpc-oa · 后台 UI 独立脚本（OA-2491 / 铁律 32 / CHG-466）
 *
 * 只做**渐进增强**，不承载任何业务逻辑（业务动作仍走各页内联脚本与服务端）。
 *
 * 1. 宽表兜底：未包在 `.tco-table-wrap` 内的 `<table class="widefat|tco-tbl">` 自动加包裹，
 *    避免窄屏横向溢出（兜底即可，页面应显式包裹）；
 * 2. 标记 `tco-oa-ready` 供样式/调试定位。
 *
 * 不使用任何主题全局变量/函数；仅依赖浏览器 API。
 */
(function () {
    'use strict';

    function wrapTables() {
        var tables = document.querySelectorAll('.wrap table.widefat, .wrap table.tco-tbl');
        Array.prototype.forEach.call(tables, function (table) {
            var parent = table.parentElement;
            if (parent && parent.classList && parent.classList.contains('tco-table-wrap')) {
                return;
            }
            var box = document.createElement('div');
            box.className = 'tco-table-wrap';
            parent.insertBefore(box, table);
            box.appendChild(table);
        });
    }

    function markReady() {
        document.body.classList.add('tco-oa-ready');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            wrapTables();
            markReady();
        });
    } else {
        wrapTables();
        markReady();
    }
})();
