# Company Software

A web-based inventory, wholesale and retail-sales management system for record stores, with Discogs marketplace integration.

## What it does

- **Catalogue & stock** – manage records (artists, labels, formats) and track stock quantities per area and location.
- **Sales, purchases and wholesale** – register retail sales, incoming purchases from suppliers and outgoing wholesale orders to customers, including backorders for unfulfilled quantities.
- **Bulk import/export** – load catalogues from spreadsheets with a staging step before commit, and export records and sales to Excel (large exports run in the background).
- **Discogs integration** – list records on the Discogs marketplace and sync/reconcile incoming orders with internal documents.
- **Barcode labels** – generate printable PDF barcode labels.
- **Roles & permissions** – admin, manager and operator roles; managers and operators only see the locations assigned to them.
- **In-app user manual** – documentation served inside the application.

## What it is for

It replaces spreadsheets and manual stock counts for small and medium retailers who sell physical products across several locations and online channels, giving a single, consistent view of inventory, documents and orders.

## How it was built

- **Backend:** Laravel 11 (PHP 8.2+), MySQL, queued jobs for exports and Discogs sync, spatie/laravel-permission for roles, spatie medialibrary, maatwebsite/excel, dompdf for PDF labels.
- **Frontend:** Inertia.js 2 with React 18 and TypeScript, built with Vite; Tailwind CSS and Radix UI (shadcn-style components).
- **Tooling:** Docker / Laravel Sail, PHPUnit feature tests, Cypress end-to-end tests, ESLint, Prettier and Laravel Pint.
