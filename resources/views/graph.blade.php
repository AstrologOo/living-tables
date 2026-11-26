<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Graph viewer</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
        }
        header {
            padding: 12px 20px;
            background: #1e293b;
            border-bottom: 1px solid #334155;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        header h1 { font-size: 16px; margin: 0; font-weight: 600; }
        header button {
            background: #334155;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 6px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        }
        header button:hover { background: #475569; }
        .layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            height: calc(100vh - 49px);
        }
        #graph-panel {
            overflow: auto;
            padding: 20px;
        }
        #side-panel {
            border-left: 1px solid #334155;
            overflow-y: auto;
            padding: 16px;
        }
        .legend { display: flex; gap: 12px; font-size: 12px; color: #94a3b8; }
        .legend span { display: inline-flex; align-items: center; gap: 4px; }
        .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .node-rect {
            cursor: pointer;
            stroke: #0f172a;
            stroke-width: 2;
        }
        .node-rect.selected { stroke: #f8fafc; stroke-width: 3; }
        .node-label { font-size: 12px; fill: #0f172a; pointer-events: none; font-weight: 600; }
        .node-sub { font-size: 10px; fill: #0f172a; pointer-events: none; opacity: .75; }
        .edge { stroke: #475569; stroke-width: 1.5; fill: none; }
        section { margin-bottom: 24px; }
        section h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-weight: 600; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            color: #0f172a;
        }
        .muted { color: #64748b; font-size: 12px; }
        input[type=password] {
            background: #1e293b;
            border: 1px solid #334155;
            color: #e2e8f0;
            border-radius: 6px;
            padding: 5px 8px;
            font-size: 13px;
        }
        .notif {
            border: 1px solid #334155;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 8px;
            font-size: 12px;
        }
        .notif.unread { border-color: #3b82f6; }
        .notif button {
            margin-top: 6px;
            font-size: 11px;
            background: #334155;
            color: #e2e8f0;
            border: 1px solid #475569;
            border-radius: 4px;
            padding: 2px 8px;
            cursor: pointer;
        }
        pre { white-space: pre-wrap; word-break: break-word; margin: 4px 0; font-size: 11px; color: #cbd5e1; }
    </style>
</head>
<body>
    <header>
        <h1>Graph viewer</h1>
        <input type="password" id="token-input" placeholder="API token" autocomplete="off">
        <button onclick="saveToken()">Use token</button>
        <button onclick="forgetToken()">Forget</button>
        <button onclick="reload()">Refresh</button>
        <span id="auth-message" class="muted"></span>
        <div class="legend">
            <span><i class="dot" style="background:#22c55e"></i>fresh</span>
            <span><i class="dot" style="background:#eab308"></i>stale</span>
            <span><i class="dot" style="background:#3b82f6"></i>processing</span>
            <span><i class="dot" style="background:#ef4444"></i>failed</span>
            <span><i class="dot" style="background:#f97316"></i>schema_drift</span>
        </div>
    </header>

    <div class="layout">
        <div id="graph-panel">
            <svg id="graph-svg"></svg>
        </div>

        <div id="side-panel">
            <section id="node-details">
                <h2>Node</h2>
                <p class="muted">Click a node to see its data.</p>
            </section>

            <section>
                <h2>Notifications</h2>
                <div id="notifications-list"></div>
            </section>
        </div>
    </div>

    <script>
        const STATUS_COLORS = {
            fresh: '#22c55e',
            stale: '#eab308',
            processing: '#3b82f6',
            failed: '#ef4444',
            schema_drift: '#f97316',
        };

        let selectedNodeId = null;

        // The token stays in this browser until "Forget".
        const TOKEN_KEY = 'graph-viewer-token';

        function saveToken() {
            const token = document.getElementById('token-input').value.trim();
            if (!token) return;
            localStorage.setItem(TOKEN_KEY, token);
            document.getElementById('token-input').value = '';
            reload();
        }

        function forgetToken() {
            localStorage.removeItem(TOKEN_KEY);
            selectedNodeId = null;
            document.getElementById('node-details').innerHTML = '<h2>Node</h2><p class="muted">Click a node to see its data.</p>';
            reload();
        }

        // Every request goes through here. Returns null if it failed, after saying why.
        async function api(path, options = {}) {
            const token = localStorage.getItem(TOKEN_KEY);
            const message = document.getElementById('auth-message');

            if (!token) {
                message.textContent = 'Paste an API token (from /api/login) to see your graph.';
                return null;
            }

            const res = await fetch(path, {
                ...options,
                headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            });

            if (!res.ok) {
                message.textContent = res.status === 401
                    ? 'The token is not valid or has expired.'
                    : `Request failed: HTTP ${res.status}.`;
                return null;
            }

            message.textContent = '';
            return res.status === 204 ? true : res.json();
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        }

        function reload() {
            loadGraph();
            loadNotifications();
        }

        async function loadGraph() {
            const graph = await api('/api/graph');
            renderGraph(graph?.nodes ?? [], graph?.edges ?? []);
        }

        function computeLayers(nodes, edges) {
            const parentsOf = {};
            nodes.forEach(n => parentsOf[n.id] = []);
            edges.forEach(e => {
                if (parentsOf[e.node_id]) parentsOf[e.node_id].push(e.depends_on_id);
            });

            const depth = {};
            function depthOf(id, seen) {
                if (id in depth) return depth[id];
                if (seen.has(id)) return 0; // shouldn't happen (cycles are rejected on insert), fail safe
                seen.add(id);
                const parents = parentsOf[id] || [];
                depth[id] = parents.length === 0 ? 0 : 1 + Math.max(...parents.map(p => depthOf(p, seen)));
                return depth[id];
            }
            nodes.forEach(n => depthOf(n.id, new Set()));

            const columns = [];
            nodes.forEach(n => {
                const d = depth[n.id];
                columns[d] = columns[d] || [];
                columns[d].push(n);
            });

            return columns;
        }

        function renderGraph(nodes, edges) {
            const svg = document.getElementById('graph-svg');
            svg.innerHTML = '';

            if (nodes.length === 0) {
                svg.setAttribute('width', 300);
                svg.setAttribute('height', 60);
                const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                text.setAttribute('x', 10);
                text.setAttribute('y', 30);
                text.setAttribute('fill', '#94a3b8');
                text.textContent = localStorage.getItem(TOKEN_KEY) ? 'No nodes yet.' : 'No token.';
                svg.appendChild(text);
                return;
            }

            const columns = computeLayers(nodes, edges);
            const colWidth = 210, rowHeight = 90, nodeW = 160, nodeH = 54;
            const positions = {};

            columns.forEach((col, colIndex) => {
                col.forEach((n, rowIndex) => {
                    positions[n.id] = {
                        x: colIndex * colWidth + 40,
                        y: rowIndex * rowHeight + 40,
                    };
                });
            });

            const width = columns.length * colWidth + 40;
            const height = Math.max(...columns.map(c => c.length)) * rowHeight + 40;
            svg.setAttribute('width', width);
            svg.setAttribute('height', height);

            const ns = 'http://www.w3.org/2000/svg';

            const defs = document.createElementNS(ns, 'defs');
            defs.innerHTML = `<marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                <path d="M 0 0 L 10 5 L 0 10 z" fill="#475569"></path>
            </marker>`;
            svg.appendChild(defs);

            edges.forEach(e => {
                const from = positions[e.depends_on_id];
                const to = positions[e.node_id];
                if (!from || !to) return;

                const x1 = from.x + nodeW, y1 = from.y + nodeH / 2;
                const x2 = to.x, y2 = to.y + nodeH / 2;
                const midX = (x1 + x2) / 2;

                const path = document.createElementNS(ns, 'path');
                path.setAttribute('class', 'edge');
                path.setAttribute('marker-end', 'url(#arrow)');
                path.setAttribute('d', `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`);
                svg.appendChild(path);
            });

            nodes.forEach(n => {
                const pos = positions[n.id];
                const g = document.createElementNS(ns, 'g');

                // Shown on hover, as the label may be shortened.
                const title = document.createElementNS(ns, 'title');
                title.textContent = n.name;
                g.appendChild(title);

                const rect = document.createElementNS(ns, 'rect');
                rect.setAttribute('class', 'node-rect' + (n.id === selectedNodeId ? ' selected' : ''));
                rect.setAttribute('x', pos.x);
                rect.setAttribute('y', pos.y);
                rect.setAttribute('width', nodeW);
                rect.setAttribute('height', nodeH);
                rect.setAttribute('rx', 8);
                rect.setAttribute('fill', STATUS_COLORS[n.status] || '#94a3b8');
                rect.addEventListener('click', () => selectNode(n.id));
                g.appendChild(rect);

                const label = document.createElementNS(ns, 'text');
                label.setAttribute('class', 'node-label');
                label.setAttribute('x', pos.x + 10);
                label.setAttribute('y', pos.y + 22);
                label.textContent = n.name;
                g.appendChild(label);

                const sub = document.createElementNS(ns, 'text');
                sub.setAttribute('class', 'node-sub');
                sub.setAttribute('x', pos.x + 10);
                sub.setAttribute('y', pos.y + 38);
                sub.textContent = `${n.type} · ${n.status}`;
                g.appendChild(sub);

                svg.appendChild(g);
                // Text can be measured only once it is in the document.
                fitText(label, nodeW - 20);
            });
        }

        // Cuts an SVG text to the width, ending it with an ellipsis.
        function fitText(element, maxWidth) {
            const characters = Array.from(element.textContent);

            while (characters.length > 0 && element.getComputedTextLength() > maxWidth) {
                characters.pop();
                element.textContent = characters.join('') + '…';
            }
        }

        async function selectNode(nodeId) {
            selectedNodeId = nodeId;
            loadGraph();

            const details = document.getElementById('node-details');
            details.innerHTML = '<h2>Node</h2><p class="muted">Loading…</p>';

            const [rows, impact] = await Promise.all([
                api(`/api/nodes/${encodeURIComponent(nodeId)}/rows?per_page=20`),
                api(`/api/nodes/${encodeURIComponent(nodeId)}/impact`),
            ]);

            if (!rows || !impact) {
                details.innerHTML = '<h2>Node</h2><p class="muted">Failed to load.</p>';
                return;
            }

            const columns = Object.entries(rows.columns || {});

            let html = '<h2>Node</h2>';
            html += `<p class="muted">${escapeHtml(nodeId)}</p>`;
            html += `<p>Rows: <strong>${rows.meta.total}</strong> &middot; Affects <strong>${impact.affected_count}</strong> downstream node(s)</p>`;

            if (rows.data.length === 0) {
                html += '<p class="muted">No data yet.</p>';
            } else {
                html += '<div style="overflow-x:auto"><table><thead><tr>';
                columns.forEach(([colId, col]) => html += `<th>${escapeHtml(col.name)}</th>`);
                html += '</tr></thead><tbody>';
                rows.data.forEach(row => {
                    html += '<tr>';
                    columns.forEach(([colId]) => html += '<td>' + formatCell(row[colId]) + '</td>');
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }

            details.innerHTML = html;
        }

        function formatCell(value) {
            if (value === null || value === undefined) return '<span class="muted">—</span>';
            return escapeHtml(typeof value === 'object' ? JSON.stringify(value) : value);
        }

        async function loadNotifications() {
            const list = document.getElementById('notifications-list');
            const notifications = await api('/api/notifications');

            if (!notifications) {
                list.innerHTML = '<p class="muted">Not loaded.</p>';
                return;
            }

            if (notifications.length === 0) {
                list.innerHTML = '<p class="muted">No notifications.</p>';
                return;
            }

            list.innerHTML = notifications.map(n => {
                const read = n.read_at ? '' : 'unread';
                const action = n.read_at
                    ? '<span class="muted">read</span>'
                    : '<button data-read="' + escapeHtml(n.id) + '">Mark read</button>';

                return `
                <div class="notif ${read}">
                    <strong>${escapeHtml(n.type)}</strong> <span class="muted">${escapeHtml(new Date(n.created_at).toLocaleString())}</span>
                    <pre>${escapeHtml(JSON.stringify(n.payload))}</pre>
                    ${action}
                </div>
            `;
            }).join('');
        }

        document.getElementById('notifications-list').addEventListener('click', async event => {
            const id = event.target.dataset.read;
            if (!id) return;
            await api(`/api/notifications/${encodeURIComponent(id)}/read`, { method: 'POST' });
            loadNotifications();
        });

        reload();
    </script>
</body>
</html>
