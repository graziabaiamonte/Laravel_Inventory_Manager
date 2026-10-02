// Records import: upload a spreadsheet, review the draft, then complete it. The import
// tables had leftover code removed, so the whole flow is exercised through the UI.
describe('Records import', () => {
    // Builds the import spreadsheet on the server, the repository is mounted in both containers
    const writeSpreadsheet = () =>
        cy.php(`
            $headers = ['ID', 'cat#', 'artist', 'title', 'fmt', 'label', 'barcode', 'q', 'retail_price', 'Own Warehouse - Own Warehouse Area (default)'];
            $row = ['', 'CY-IMP', 'Cypress Artist', 'Imported Cypress Title', 'LP', 'Cypress Label', '8011111111116', 3, 20, 3];
            $sheet = new PhpOffice\\PhpSpreadsheet\\Spreadsheet();
            $sheet->getActiveSheet()->fromArray([$headers, $row]);
            $sheet->getActiveSheet()->getCell('G2')->setValueExplicit('8011111111116', PhpOffice\\PhpSpreadsheet\\Cell\\DataType::TYPE_STRING);
            File::ensureDirectoryExists(storage_path('app/cypress'));
            (new PhpOffice\\PhpSpreadsheet\\Writer\\Xlsx($sheet))->save(storage_path('app/cypress/records-import.xlsx'));
            return true;
        `);

    beforeEach(() => {
        cy.resetDatabase();
        writeSpreadsheet();
        cy.loginAs('admin@example.com');
    });

    it('imports a new record with its stock', () => {
        cy.visit('/records-import/create');
        cy.get('input[type="file"][accept=".xlsx"]').first().selectFile('storage/app/cypress/records-import.xlsx', { force: true });
        cy.contains('button', 'Carica').click();

        // The first submit parses the spreadsheet into the preview, the second one saves the draft
        cy.get('tbody input').filter((_, input) => input.value === 'CY-IMP').should('have.length', 1);
        cy.contains('button', 'Carica').click();

        cy.location('pathname').should('match', /^\/records-import\/\d+\/edit$/);
        cy.intercept({ method: /PUT|PATCH|POST/, pathname: /^\/records-import\/\d+$/ }).as('update');
        cy.contains("Completa l'import dei record").click();
        cy.contains('button', 'Aggiorna').click();
        cy.wait('@update');

        cy.php("App\\Models\\Record::where('cat_number', 'CY-IMP')->value('title')").should('eq', 'Imported Cypress Title');
        cy.php(
            "App\\Models\\Stock::whereHas('record', fn ($q) => $q->where('cat_number', 'CY-IMP'))->whereHas('area', fn ($q) => $q->where('name', 'Own Warehouse Area'))->value('quantity')",
        ).should('eq', 3);
    });
});
