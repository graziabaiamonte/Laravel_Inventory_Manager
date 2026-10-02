<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    @include('pdf.partials._barcode-styles', ['multi' => true])
</head>
<body>
    @foreach ($labels as $label)
        @include('pdf.partials._barcode-label', $label)
    @endforeach

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
