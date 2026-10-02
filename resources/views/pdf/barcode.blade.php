<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    @include('pdf.partials._barcode-styles')
</head>
<body>
    @include('pdf.partials._barcode-label')
    <script>
        window.onload = function () {
            window.print();
        };
        window.onafterprint = function () {
            window.close();
        };
    </script>
</body>
</html>
