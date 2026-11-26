<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Graph API docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        window.onload = () => {
            window.ui = SwaggerUIBundle({
                url: '/docs/openapi.yaml',
                dom_id: '#swagger-ui',
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
            });
        };
    </script>
</body>
</html>
